<?php

namespace Tests\Feature;

use App\Models\DiningTable;
use App\Models\Item;
use App\Models\Location;
use App\Models\PosOrder;
use App\Models\TableCall;
use App\Models\Task;
use App\Services\TaskService;
use App\Support\Features;

/** Branch kitchen display, waiters for tables (calls → tasks), bKash / Nagad from the QR menu, cashier gives deliveries, demo list moved. */
class TableWaitersPaymentKdsTest extends GroupOpsTestCase
{
    protected function gul(): int
    {
        return Location::where('code', 'BR-GUL')->value('id');
    }

    protected function table(string $name, ?int $loc = null): DiningTable
    {
        return DiningTable::where('location_id', $loc ?? $this->gul())->where('name', $name)->firstOrFail();
    }

    protected function qrOrder(DiningTable $t, int $qty = 1): array
    {
        return $this->postJson(route('qr.order', $t->qr_token), ['lines' => [['item_id' => Item::where('code', 'MN-001')->value('id'), 'qty' => $qty]]])->assertOk()->json();
    }

    public function test_kitchen_display_is_per_branch(): void
    {
        $gulT = $this->table('T1');
        $j = $this->qrOrder($gulT);
        $gulOrder = PosOrder::where('number', $j['number'])->first();
        $this->flushSession();
        $other = DiningTable::where('location_id', '!=', $this->gul())->whereHas('location', fn ($q) => $q->where('type', 'BRANCH'))->firstOrFail();
        $otherOrder = PosOrder::where('number', $this->qrOrder($other)['number'])->first();

        // Gulshan head chef sees only the Gulshan kitchen, even when asking for another branch
        $this->actingAs($this->u('E-2003'));
        $this->get(route('pos.kds'))->assertOk()->assertSee('Sufra Gulshan')->assertDontSee('<select name="location"', false);
        $ids = collect($this->getJson(route('pos.kds.feed', ['location' => $other->location_id]))->assertOk()->json())->pluck('id');
        $this->assertTrue($ids->contains($gulOrder->id));
        $this->assertFalse($ids->contains($otherOrder->id));
        // … and cannot mark the other branch's food ready
        $this->postJson(route('pos.kds.order_ready', $otherOrder))->assertForbidden();

        // Super Admin picks the kitchen
        $this->actingAs($this->u('SA-0001'));
        $this->get(route('pos.kds', ['location' => $other->location_id]))->assertOk()->assertSee('<select name="location"', false);
        $ids = collect($this->getJson(route('pos.kds.feed', ['location' => $other->location_id]))->json())->pluck('id');
        $this->assertTrue($ids->contains($otherOrder->id));
        $this->assertFalse($ids->contains($gulOrder->id));

        // "whole order ready" clears every item of the order
        $this->actingAs($this->u('E-2003'))->postJson(route('pos.kds.order_ready', $gulOrder))->assertOk();
        $this->assertSame(0, $gulOrder->lines()->where('kot_status', 'NEW')->count());
        $this->assertFalse(collect($this->getJson(route('pos.kds.feed'))->json())->pluck('id')->contains($gulOrder->id));
    }

    public function test_manager_gives_tables_to_waiters_and_calls_become_tasks(): void
    {
        $t1 = $this->table('T1');
        $t2 = $this->table('T2');
        $karim = $this->u('E-2005');
        $belal = $this->u('E-2006');

        // one waiter on two tables, two waiters on one table
        $this->actingAs($this->u('E-2001'))->get(route('tables.waiters'))->assertOk()->assertSee('Karim Mia')->assertDontSee('Mitu Akter');
        $this->post(route('tables.waiters.save'), ['location' => $this->gul(), 'w' => [$t1->id => [$karim->id, $belal->id], $t2->id => [$karim->id]]])->assertSessionHas('ok');
        $this->assertEqualsCanonicalizing([$karim->id, $belal->id], $t1->waiters()->pluck('users.id')->all());
        $this->assertEquals([$karim->id], $t2->waiters()->pluck('users.id')->all());

        // a cashier (not a candidate) and other-branch staff are ignored; a cashier cannot open the page
        $this->post(route('tables.waiters.save'), ['location' => $this->gul(), 'w' => [$t1->id => [$karim->id, $belal->id], $t2->id => [$karim->id, $this->u('E-2011')->id]]]);
        $this->assertEquals([$karim->id], $t2->waiters()->pluck('users.id')->all());
        $this->actingAs($this->u('E-2011'))->get(route('tables.waiters'))->assertForbidden();
        auth()->logout();

        // customer at T1 calls a waiter → a task for each of the two waiters (demo data may already hold open calls)
        TableCall::query()->update(['status' => 'DONE']);
        $this->postJson(route('qr.call', $t1->qr_token), ['type' => 'WAITER'])->assertOk();
        $call = TableCall::where('dining_table_id', $t1->id)->latest('id')->first();
        $tasks = Task::where('linked_type', 'TableCall')->where('linked_id', $call->id)->get();
        $this->assertEqualsCanonicalizing([$karim->id, $belal->id], $tasks->pluck('assignee_id')->all());
        $this->assertStringContainsString('Table T1', $tasks->first()->title);
        $this->assertSame('URGENT', $tasks->first()->priority);

        // Karim starts (= coming) and finishes: the call is done, Belal's task closes by itself
        $mine = $tasks->firstWhere('assignee_id', $karim->id);
        TaskService::start($mine, $karim);
        $this->assertSame('COMING', $call->fresh()->status);
        $this->assertSame($karim->id, $call->fresh()->answered_by);
        TaskService::complete($mine->fresh(), $karim);
        $this->assertSame('DONE', $call->fresh()->status);
        $this->assertSame('DONE', $tasks->firstWhere('assignee_id', $belal->id)->fresh()->status);

        // a QR order at T2 → serve task for Karim; when the kitchen is done he is told the food is ready
        $j = $this->qrOrder($t2);
        $o = PosOrder::where('number', $j['number'])->first();
        $orderCall = TableCall::where('pos_order_id', $o->id)->where('type', 'ORDER')->firstOrFail();
        $this->assertSame(1, Task::where('linked_type', 'TableCall')->where('linked_id', $orderCall->id)->where('assignee_id', $karim->id)->count());
        $this->actingAs($this->u('E-2003'))->postJson(route('pos.kds.order_ready', $o))->assertOk();
        $this->assertTrue(\App\Models\AppNotification::where('user_id', $karim->id)->where('title', 'like', '%Food ready · Table T2%')->exists());

        // staff closing a call from the Table calls screen closes the waiters' tasks
        $this->postJson(route('qr.call', $t2->qr_token), ['type' => 'WATER'])->assertOk();
        $water = TableCall::where('dining_table_id', $t2->id)->where('type', 'WATER')->latest('id')->first();
        $this->actingAs($this->u('E-2011'))->post(route('calls.answer', [$water, 'done']));
        $this->assertSame(0, Task::where('linked_type', 'TableCall')->where('linked_id', $water->id)->whereIn('status', ['ASSIGNED', 'STARTED'])->count());

        // a table with no waiter makes no tasks (cashier / managers still get the call)
        $before = Task::count();
        auth()->logout();
        $this->postJson(route('qr.call', $this->table('T3')->qr_token), ['type' => 'BILL'])->assertOk();
        $this->assertSame($before, Task::count());
    }

    public function test_customer_pays_by_bkash_from_the_table_and_the_cashier_closes_it(): void
    {
        $t = $this->table('T4');
        $this->actingAs($this->u('E-2011'))->post(route('pos.open'), ['opening_cash' => 1000]);
        auth()->logout();

        // no number / QR set yet → no online payment
        $this->get(route('qr.table', $t->qr_token))->assertOk()->assertSee('"pay":[]', false);
        $j = $this->qrOrder($t, 2);
        $this->postJson(route('qr.pay', $t->qr_token), ['method' => 'BKASH', 'trx' => 'ABC123XYZ'])->assertStatus(422);

        Features::setSetting('qr.bkash_number', '01711000222');
        $this->get(route('qr.table', $t->qr_token))->assertOk()->assertSee('01711000222')->assertSee('I have paid')->assertSee('or by bKash from this page');
        $status = $this->getJson(route('qr.status', $t->qr_token))->json();
        $o = PosOrder::where('number', $j['number'])->first();
        $this->assertEquals((float) $o->total, $status['unclaimed']);

        // Nagad is not set up; a too-short TrxID is refused
        $this->postJson(route('qr.pay', $t->qr_token), ['method' => 'NAGAD', 'trx' => 'ABC123XYZ'])->assertStatus(422);
        $this->postJson(route('qr.pay', $t->qr_token), ['method' => 'BKASH', 'trx' => '12'])->assertStatus(422);

        $r = $this->postJson(route('qr.pay', $t->qr_token), ['method' => 'BKASH', 'trx' => ' 9ab-c12XYZ ', 'phone' => '01811 222333'])->assertOk()->json();
        $this->assertSame('bKash', $r['claim']['method']);
        $this->assertEquals(0, $r['unclaimed']);
        $o->refresh();
        $this->assertSame('OPEN', $o->status); // the cashier still checks it
        $this->assertSame('9ABC12XYZ', $o->pay_claim_trx);
        $this->assertSame('01811222333', $o->pay_claim_phone);
        $paid = TableCall::where('pos_order_id', $o->id)->where('type', 'PAID')->firstOrFail();
        $this->assertStringContainsString('TrxID 9ABC12XYZ', $paid->note);
        $this->assertTrue(\App\Models\AppNotification::where('user_id', $this->u('E-2011')->id)->where('title', 'like', '%Paid by bKash · Table T4%')->exists());

        // the same TrxID cannot pay another table's bill
        $this->flushSession();
        $this->qrOrder($this->table('T5'));
        $this->postJson(route('qr.pay', $this->table('T5')->qr_token), ['method' => 'BKASH', 'trx' => '9ABC12XYZ'])->assertStatus(422);

        // POS shows the claim on the open bill; the cashier closes it with bKash + the TrxID
        $this->actingAs($this->u('E-2011'))->get(route('pos'))->assertOk()->assertSee('9ABC12XYZ');
        $bkash = \App\Models\PaymentMethod::where('name', 'bKash')->value('id');
        $this->postJson(route('pos.pay', $o), ['payments' => [['payment_method_id' => $bkash, 'amount' => (float) $o->total, 'reference' => '9ABC12XYZ']]])->assertOk();
        $this->assertSame('PAID', $o->fresh()->status);

        // settings page has the bKash / Nagad fields
        $this->actingAs($this->u('SA-0001'))->get(route('setup.settings'))->assertOk()->assertSee('bKash payment QR code')->assertSee('Nagad merchant');
    }

    public function test_cashier_can_give_a_delivery_to_a_rider_unless_switched_off(): void
    {
        $this->actingAs($this->u('E-2011'));
        $this->post(route('pos.open'), ['opening_cash' => 1000]);
        $j = $this->postJson(route('pos.store'), ['order_type' => 'DELIVERY', 'customer_phone' => '01819000111', 'customer_name' => 'Rafsan', 'delivery_address' => 'Road 5, Gulshan', 'lines' => [['item_id' => Item::where('code', 'MN-001')->value('id'), 'qty' => 1]]])->assertOk()->json('order');
        $o = PosOrder::find($j['id']);

        Features::setSetting('delivery.cashier_assigns', 0);
        $this->post(route('delivery.action', [$o, 'assign']), ['rider_id' => $this->u('E-2019')->id])->assertSessionHas('error');
        Features::setSetting('delivery.cashier_assigns', 1);
        $this->get(route('delivery.index'))->assertOk()->assertSee('Riaz Uddin');
        $this->post(route('delivery.action', [$o, 'assign']), ['rider_id' => $this->u('E-2019')->id])->assertSessionHas('ok');
        $this->assertSame($this->u('E-2019')->id, $o->fresh()->rider_id);
    }

    public function test_demo_accounts_are_only_for_the_super_admin(): void
    {
        $this->get(route('login'))->assertOk()->assertDontSee('Demo accounts')->assertDontSee('SA-0001');
        $this->actingAs($this->u('E-1002'))->get(route('super.demo'))->assertForbidden();
        $this->actingAs($this->u('SA-0001'))->get(route('super.demo'))->assertOk()->assertSee('SA-0001')->assertSee('123456')->assertSee('Mitu Akter');
        $this->get(route('home'))->assertSee('Demo accounts');
    }
}
