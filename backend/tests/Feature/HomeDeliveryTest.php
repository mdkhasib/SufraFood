<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Item;
use App\Models\Location;
use App\Models\PaymentMethod;
use App\Models\PosOrder;
use App\Models\Task;
use App\Models\User;
use App\Services\PayrollService;
use App\Support\Features;

/** Home delivery: counter / online order → branch kitchen → manager gives a rider → rider task → delivered → cash in → bonus in payroll. */
class HomeDeliveryTest extends GroupOpsTestCase
{
    protected array $customer = ['customer_name' => 'Rafsan Chowdhury', 'customer_phone' => '01819000111', 'delivery_address' => 'House 22, Road 11, Block C', 'delivery_area' => 'Gulshan-1', 'delivery_note' => 'Call at the gate'];

    /** Cashier takes a delivery order at the counter (sends KOT). */
    protected function counterOrder(array $extra = []): PosOrder
    {
        $this->actingAs($this->u('E-2011'));
        if (! \App\Services\PosService::currentShift($this->u('E-2011'))) {
            $this->post(route('pos.open'), ['opening_cash' => 1000]);
        }
        $j = $this->postJson(route('pos.store'), array_merge($this->customer, ['order_type' => 'DELIVERY', 'lines' => [['item_id' => Item::where('code', 'MN-001')->value('id'), 'qty' => 2]]], $extra))->assertOk()->json('order');

        return PosOrder::find($j['id']);
    }

    protected function kitchenReady(PosOrder $o): void
    {
        $this->actingAs($this->u('E-2003')); // branch head chef (kitchen display)
        foreach ($o->lines as $l) {
            $this->postJson(route('pos.kds.ready', $l->id))->assertOk();
        }
    }

    protected function notes(string $code): \Illuminate\Support\Collection
    {
        return AppNotification::where('user_id', $this->u($code)->id)->pluck('title');
    }

    public function test_counter_delivery_full_flow_to_cash_and_shift(): void
    {
        // no address → refused
        $this->actingAs($this->u('E-2011'));
        $this->post(route('pos.open'), ['opening_cash' => 1000]);
        $this->postJson(route('pos.store'), ['order_type' => 'DELIVERY', 'customer_phone' => '01819000111', 'lines' => [['item_id' => Item::where('code', 'MN-001')->value('id'), 'qty' => 1]]])
            ->assertStatus(422)->assertJsonPath('message', 'A delivery order needs the customer’s phone number and address.');

        $o = $this->counterOrder();
        $this->assertSame('COOKING', $o->delivery_status);
        $this->assertSame('POS', $o->source);
        $this->assertSame('Gulshan-1', $o->delivery_area);
        $this->assertTrue($this->notes('E-2001')->contains(fn ($t) => str_contains($t, 'New home delivery '.$o->number)));

        // kitchen screen shows it as a home delivery
        $this->actingAs($this->u('E-2003'));
        $feed = collect($this->getJson(route('pos.kds.feed'))->assertOk()->json())->firstWhere('id', $o->id);
        $this->assertStringContainsString('Rafsan Chowdhury', $feed['delivery']);

        // manager gives it to the Gulshan rider while it cooks → rider task with the customer's details
        $this->actingAs($this->u('E-2001'))->get(route('delivery.index'))->assertOk()->assertSee($o->number)->assertSee('Riaz Uddin')->assertSee('No rider yet');
        $this->post(route('delivery.action', [$o, 'assign']), ['rider_id' => $this->u('E-2019')->id])->assertSessionHas('ok');
        $o->refresh();
        $task = Task::find($o->delivery_task_id);
        $this->assertSame($this->u('E-2019')->id, $task->assignee_id);
        $this->assertSame('HIGH', $task->priority);
        $this->assertStringContainsString('01819000111', $task->description);
        $this->assertStringContainsString('House 22, Road 11', $task->description);
        $this->assertStringContainsString('Collect cash: ৳'.number_format($o->total, 2), $task->description);
        $this->assertTrue($this->notes('E-2019')->contains(fn ($t) => str_contains($t, 'Delivery for you')));

        // rider sees it in the app with the delivery card; cannot pick up before the kitchen is done
        $this->api('E-2019');
        $t = collect($this->getJson('/api/tasks')->assertOk()->json())->firstWhere('id', $task->id);
        $this->assertSame('01819000111', $t['delivery']['phone']);
        $this->assertSame((float) $o->total, (float) $t['delivery']['collect']);
        $this->assertFalse($t['delivery']['kitchen_ready']);
        $this->assertStringContainsString('google.com/maps', $t['delivery']['maps']);
        $this->postJson("/api/tasks/{$task->id}/start")->assertStatus(422)->assertJsonPath('message', 'The kitchen has not marked it ready yet. Wait at the kitchen counter.');

        // kitchen marks it ready → rider is told
        $this->kitchenReady($o);
        $this->assertSame('READY', $o->fresh()->delivery_status);
        $this->assertTrue($this->notes('E-2019')->contains(fn ($t) => str_contains($t, 'Food ready')));

        // another waiter cannot touch it; the rider picks up and delivers with the cash
        $this->api('E-2012');
        $this->postJson("/api/tasks/{$task->id}/start")->assertStatus(422);
        $this->api('E-2019');
        $this->postJson("/api/tasks/{$task->id}/start")->assertOk();
        $this->assertSame('PICKED_UP', $o->fresh()->delivery_status);
        $this->assertSame('STARTED', $task->fresh()->status);
        $this->postJson("/api/tasks/{$task->id}/complete", ['cash' => $o->total, 'note' => 'Handed to the guard'])->assertOk();
        $o->refresh();
        $this->assertSame('DELIVERED', $o->delivery_status);
        $this->assertSame('DONE', $task->fresh()->status);
        $this->assertEquals(60.0, $o->rider_fee); // Gulshan rate
        $this->assertEquals($o->total, $o->cash_collected);
        $this->assertSame('OPEN', $o->status); // cash still with the rider
        $this->assertTrue($this->notes('E-2001')->contains(fn ($t) => str_contains($t, 'Delivered '.$o->number)));

        // cashier takes the cash from the rider → bill paid into her shift
        $this->actingAs($this->u('E-2011'))->get(route('delivery.index'))->assertOk()->assertSee('cash with riders');
        $this->post(route('delivery.action', [$o, 'cash']))->assertSessionHas('ok');
        $o->refresh();
        $this->assertSame('PAID', $o->status);
        $shift = \App\Services\PosService::currentShift($this->u('E-2011'));
        $this->assertSame($shift->id, $o->cashier_shift_id);
        $this->post(route('pos.close'), ['counted_cash' => 1000 + $o->total]);
        $this->assertEquals(0.0, (float) $shift->fresh()->difference);
    }

    public function test_online_team_orders_for_a_branch_and_only_that_branch_assigns(): void
    {
        $sup = $this->u('E-1080');
        $dhn = Location::where('code', 'BR-DHN')->first();
        // online team: board + new order form, no POS
        $this->actingAs($sup)->get(route('delivery.create'))->assertOk()->assertSee('Sufra Dhanmondi')->assertSee('Already paid');
        $this->get(route('pos'))->assertForbidden();

        $j = $this->postJson(route('delivery.store'), array_merge($this->customer, [
            'location_id' => $dhn->id, 'payment_mode' => 'PREPAID', 'payment_method_id' => PaymentMethod::where('name', 'bKash')->value('id'), 'reference' => 'BK7781',
            'lines' => [['item_id' => Item::where('code', 'MN-001')->value('id'), 'qty' => 1], ['item_id' => Item::where('code', 'MN-006')->value('id'), 'qty' => 2]],
        ]))->assertOk()->json();
        $o = PosOrder::find($j['order']['id']);
        $this->assertSame('ONLINE', $o->source);
        $this->assertSame('PAID', $o->status);
        $this->assertSame('PREPAID', $o->payment_mode);
        $this->assertSame($dhn->id, $o->location_id);
        $this->assertTrue($this->notes('E-3001')->contains(fn ($t) => str_contains($t, 'New home delivery')));
        $this->assertFalse($this->notes('E-2001')->contains(fn ($t) => str_contains($t, $o->number)));

        // Gulshan manager cannot run a Dhanmondi delivery, nor give it to a Gulshan rider
        $this->actingAs($this->u('E-2001'))->post(route('delivery.action', [$o, 'assign']), ['rider_id' => $this->u('E-2019')->id])->assertSessionHas('error');
        $this->actingAs($this->u('E-3001'))->post(route('delivery.action', [$o, 'assign']), ['rider_id' => $this->u('E-2019')->id])->assertSessionHas('error');
        $this->post(route('delivery.action', [$o, 'assign']), ['rider_id' => $this->u('E-3009')->id])->assertSessionHas('ok');

        // switch rider: first task cancelled, second rider gets a new one
        $first = $o->fresh()->delivery_task_id;
        $this->post(route('delivery.action', [$o, 'assign']), ['rider_id' => $this->u('E-3007')->id])->assertSessionHas('ok');
        $this->assertSame('CANCELLED', Task::find($first)->status);
        $this->assertSame($this->u('E-3007')->id, $o->fresh()->rider_id);

        // prepaid: rider collects nothing
        $this->api('E-3007');
        $t = collect($this->getJson('/api/tasks')->json())->firstWhere('id', $o->fresh()->delivery_task_id);
        $this->assertEquals(0, $t['delivery']['collect']);
        $this->assertTrue($t['delivery']['paid']);

        // online agent hears when it is on the way and delivered
        Features::setSetting('delivery.need_kitchen_ready', 0);
        Features::flush();
        $this->postJson("/api/tasks/{$t['id']}/start")->assertOk();
        $this->postJson("/api/tasks/{$t['id']}/complete")->assertOk();
        $this->assertSame('DELIVERED', $o->fresh()->delivery_status);
        $this->assertEquals(50.0, $o->fresh()->rider_fee); // Dhanmondi uses the default rate
        $this->assertTrue($this->notes('E-1080')->contains(fn ($t) => str_contains($t, 'On the way')));
        $this->assertTrue($this->notes('E-1080')->contains(fn ($t) => str_contains($t, 'Delivered')));
        $this->actingAs($sup)->get(route('delivery.index', ['view' => 'done']))->assertOk()->assertSee($o->number);
    }

    public function test_cancel_and_short_cash_need_reasons(): void
    {
        $o = $this->counterOrder();
        $this->actingAs($this->u('E-2001'))->post(route('delivery.action', [$o, 'assign']), ['rider_id' => $this->u('E-2013')->id])->assertSessionHas('ok');
        $this->kitchenReady($o);
        $this->api('E-2013');
        $tid = $o->fresh()->delivery_task_id;
        $this->postJson("/api/tasks/$tid/start")->assertOk();
        // short cash without a note is refused
        $this->postJson("/api/tasks/$tid/complete", ['cash' => 100])->assertStatus(422);

        // a second order is cancelled by the manager: void, rider task cancelled
        $o2 = $this->counterOrder();
        $this->actingAs($this->u('E-2001'))->post(route('delivery.action', [$o2, 'assign']), ['rider_id' => $this->u('E-2019')->id]);
        $this->post(route('delivery.action', [$o2, 'cancel']), ['reason' => 'Customer not answering'])->assertSessionHas('ok');
        $o2->refresh();
        $this->assertSame('VOID', $o2->status);
        $this->assertSame('CANCELLED', $o2->delivery_status);
        $this->assertSame('CANCELLED', Task::find($o2->delivery_task_id)->status);
        // a waiter who is not a manager cannot cancel
        $this->actingAs($this->u('E-2012'))->get(route('delivery.index'))->assertForbidden();
    }

    public function test_switch_off_in_company_settings_and_branch_rates(): void
    {
        $sa = $this->u('SA-0001');
        $gul = Location::where('code', 'BR-GUL')->first();
        $this->actingAs($sa)->get(route('setup.settings'))->assertOk()->assertSee('Home delivery')->assertSee('Rider bonus per delivery, branch by branch')->assertSee('branch_rate['.$gul->id.']', false);
        $this->post(route('setup.settings.save'), ['branch_rate' => [$gul->id => '75'], 'delivery__rate_default' => '40'])->assertSessionHas('ok');
        Features::flush();
        $this->assertEquals(75.0, $gul->fresh()->delivery_rate);
        $this->assertEquals(40.0, \App\Support\Delivery::rate(Location::where('code', 'BR-MIR')->first()));

        // employees: tick who can deliver
        $this->get(route('crud.edit', ['users', $this->u('E-2016')->id]))->assertOk()->assertSee('Can do home delivery (rider)');

        // switch OFF → board gone, menu gone, counter "delivery" orders are plain takeaway-style orders again
        $this->post(route('setup.settings.save'), ['delivery__enabled__present' => 1])->assertSessionHas('ok');
        Features::flush();
        $this->assertFalse(\App\Support\Delivery::on());
        $this->actingAs($this->u('E-2001'))->get(route('delivery.index'))->assertForbidden();
        $this->get(route('home'))->assertDontSee(route('delivery.index'), false);
        $this->actingAs($this->u('E-1080'))->postJson(route('delivery.store'), $this->customer)->assertForbidden();
        $o = $this->counterOrder(['delivery_address' => '']);
        $this->assertNull($o->delivery_status);

        // back ON
        $this->actingAs($sa)->post(route('setup.settings.save'), ['delivery__enabled__present' => 1, 'delivery__enabled' => 1]);
        Features::flush();
        $this->assertTrue(\App\Support\Delivery::on());
        $this->actingAs($this->u('E-2001'))->get(route('home'))->assertSee(route('delivery.index'), false);
    }

    public function test_delivery_bonus_goes_into_payroll_and_report(): void
    {
        PosOrder::where('delivery_status', 'DELIVERED')->update(['delivered_at' => now()->subMonths(2)]); // demo history out of this month
        foreach ([1, 2] as $i) {
            $o = $this->counterOrder();
            $this->actingAs($this->u('E-2001'))->post(route('delivery.action', [$o, 'assign']), ['rider_id' => $this->u('E-2019')->id]);
            $this->kitchenReady($o);
            $this->api('E-2019');
            $tid = $o->fresh()->delivery_task_id;
            $this->postJson("/api/tasks/$tid/start")->assertOk();
            $this->postJson("/api/tasks/$tid/complete")->assertOk();
        }
        // report
        $this->actingAs($this->u('E-2001'))->get(route('delivery.report'))->assertOk()->assertSee('Riaz Uddin')->assertSee('৳120');

        $run = PayrollService::generate(now()->format('Y-m'), $this->u('E-1020'));
        $slip = $run->payslips()->where('user_id', $this->u('E-2019')->id)->first();
        $this->assertSame(2, (int) $slip->delivery_count);
        $this->assertEquals(120.0, $slip->delivery_bonus);
        $base = $slip->basic + $slip->house_rent + $slip->medical + $slip->conveyance + $slip->ot_amount + $slip->kpi_incentive;
        $this->assertEqualsWithDelta($base + 120, $slip->gross, 0.01);
        $this->actingAs($this->u('E-1020'))->get(route('payroll.run', $run))->assertOk()->assertSee('2 → 120');
        $this->get(route('payslip', $slip))->assertOk()->assertSee('Home delivery bonus');

        // setting off → no bonus in payroll
        $this->setting('delivery.bonus_in_payroll', 0);
        $run = PayrollService::generate(now()->format('Y-m'), $this->u('E-1020'));
        $this->assertEquals(0.0, $run->payslips()->where('user_id', $this->u('E-2019')->id)->first()->delivery_bonus);
    }
}
