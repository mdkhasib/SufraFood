<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\DiningTable;
use App\Models\Item;
use App\Models\Location;
use App\Models\PaymentMethod;
use App\Models\PosOrder;
use App\Models\TableCall;
use App\Services\PosService;

/** QR table ordering: scan → menu → order to kitchen + POS → call waiter → cashier / manager / floor manager. */
class QrOrderingTest extends GroupOpsTestCase
{
    protected function gulTable(string $name = 'T3'): DiningTable
    {
        return DiningTable::where('location_id', Location::where('code', 'BR-GUL')->value('id'))->where('name', $name)->firstOrFail();
    }

    protected function notes(string $code)
    {
        return AppNotification::where('user_id', $this->u($code)->id)->pluck('title');
    }

    public function test_customer_orders_from_the_table_and_it_reaches_kitchen_and_pos(): void
    {
        $t = $this->gulTable();
        $this->assertNotEmpty($t->qr_token);
        // a cashier is on duty
        $this->actingAs($this->u('E-2011'))->post(route('pos.open'), ['opening_cash' => 1000]);
        auth()->logout();

        $this->get(route('qr.table', $t->qr_token))->assertOk()->assertSee('Table')->assertSee('T3')->assertSee('Sufra Gulshan')->assertSee('Chicken Biryani')->assertSee('Call waiter');
        $biryani = Item::where('code', 'MN-001')->first();
        $naan = Item::where('code', 'MN-005')->first();
        $j = $this->postJson(route('qr.order', $t->qr_token), ['lines' => [['item_id' => $biryani->id, 'qty' => 2], ['item_id' => $naan->id, 'qty' => 3]], 'note' => 'less spicy'])
            ->assertOk()->assertJsonPath('message', 'Your order is in the kitchen')->json();
        $this->assertCount(2, $j['items']);
        $this->assertSame('NEW', $j['items'][0]['state']);

        $o = PosOrder::where('number', $j['number'])->first();
        $this->assertSame('QR', $o->source);
        $this->assertSame('DINE_IN', $o->order_type);
        $this->assertSame($t->id, $o->dining_table_id);
        $this->assertSame('OPEN', $o->status);
        $this->assertEquals(round((2 * $biryani->sale_price + 3 * $naan->sale_price) * 1.05, 2), $o->total);
        $this->assertStringContainsString('less spicy', $o->lines->first()->note);
        $this->assertSame(\App\Services\PosService::currentShift($this->u('E-2011'))->id, $o->cashier_shift_id);

        // cashier, branch manager and floor manager are alerted; a kitchen helper is not
        foreach (['E-2011', 'E-2001', 'E-2002'] as $code) {
            $this->assertTrue($this->notes($code)->contains(fn ($n) => str_contains($n, 'QR order · Table T3')), "$code not told");
        }
        $this->assertFalse($this->notes('E-2008')->contains(fn ($n) => str_contains($n, 'QR order')));

        // kitchen screen shows it as a QR order
        $this->actingAs($this->u('E-2003'));
        $feed = collect($this->getJson(route('pos.kds.feed'))->json())->firstWhere('id', $o->id);
        $this->assertTrue($feed['qr']);
        auth()->logout();

        // ordering more from the same table goes on the same bill
        $this->postJson(route('qr.order', $t->qr_token), ['lines' => [['item_id' => $naan->id, 'qty' => 1]]])->assertOk();
        $this->assertSame(1, PosOrder::where('dining_table_id', $t->id)->where('status', 'OPEN')->count());
        $this->assertCount(3, $o->fresh()->lines);

        // another phone at another table sees nothing of this order
        $this->flushSession();
        $this->getJson(route('qr.status', $this->gulTable('T4')->qr_token))->assertOk()->assertJsonCount(0, 'items');

        // POS shows it as an open bill; the cashier takes the money
        $this->actingAs($this->u('E-2011'))->get(route('pos'))->assertOk()->assertSee($o->number);
        $this->postJson(route('pos.pay', $o), ['payments' => [['payment_method_id' => PaymentMethod::where('name', 'Cash')->value('id'), 'amount' => 2000]]])->assertOk();
        $this->assertSame('PAID', $o->fresh()->status);
    }

    public function test_order_without_a_cashier_on_duty_is_paid_into_the_shift_of_whoever_takes_the_money(): void
    {
        $t = $this->gulTable('T5');
        $j = $this->postJson(route('qr.order', $t->qr_token), ['lines' => [['item_id' => Item::where('code', 'MN-006')->value('id'), 'qty' => 1]]])->assertOk()->json();
        $o = PosOrder::where('number', $j['number'])->first();
        $this->assertNull($o->cashier_shift_id);
        $this->assertSame($this->u('E-2001')->id, $o->cashier_id); // branch head holds it until a cashier opens

        $this->actingAs($this->u('E-2011'))->post(route('pos.open'), ['opening_cash' => 0]);
        $this->get(route('pos'))->assertSee($o->number);
        $this->postJson(route('pos.pay', $o), ['payments' => [['payment_method_id' => PaymentMethod::where('name', 'Cash')->value('id'), 'amount' => $o->total]]])->assertOk();
        $o->refresh();
        $this->assertSame(PosService::currentShift($this->u('E-2011'))->id, $o->cashier_shift_id);
        $this->assertSame($this->u('E-2011')->id, $o->cashier_id);
    }

    public function test_call_waiter_reaches_staff_and_the_customer_sees_who_is_coming(): void
    {
        $t = $this->gulTable('T7');
        $this->postJson(route('qr.call', $t->qr_token), ['type' => 'WAITER'])->assertOk()->assertJsonPath('calls.0.status', 'OPEN');
        $this->postJson(route('qr.call', $t->qr_token), ['type' => 'WAITER'])->assertOk(); // pressed twice: still one call
        $this->postJson(route('qr.call', $t->qr_token), ['type' => 'OTHER', 'note' => 'Baby chair please'])->assertOk();
        $this->postJson(route('qr.call', $t->qr_token), ['type' => 'DANCE'])->assertStatus(422);
        $this->assertSame(2, TableCall::where('dining_table_id', $t->id)->count());
        foreach (['E-2011', 'E-2001', 'E-2002'] as $code) {
            $this->assertTrue($this->notes($code)->contains(fn ($n) => str_contains($n, 'Table T7: Call waiter')), "$code not told");
        }

        // floor manager sees it live and answers
        $call = TableCall::where('dining_table_id', $t->id)->where('type', 'WAITER')->first();
        $fm = $this->u('E-2002');
        $this->actingAs($fm)->get(route('calls.index'))->assertOk();
        $feed = $this->getJson(route('calls.feed'))->assertOk()->json();
        $this->assertTrue(collect($feed['calls'])->contains(fn ($c) => $c['id'] === $call->id && $c['table'] === 'T7'));
        // another branch's manager cannot answer it
        $this->actingAs($this->u('E-3001'))->postJson(route('calls.answer', [$call, 'coming']))->assertStatus(422);
        $this->assertTrue(collect($this->getJson(route('calls.feed'))->json('calls'))->isEmpty());
        $this->actingAs($fm)->postJson(route('calls.answer', [$call, 'coming']))->assertOk();
        auth()->logout();

        $calls = collect($this->getJson(route('qr.status', $t->qr_token))->json('calls'));
        $this->assertTrue($calls->contains(fn ($c) => $c['status'] === 'COMING' && $c['by'] === $fm->name));

        $this->actingAs($fm)->postJson(route('calls.answer', [$call, 'done']))->assertOk();
        $this->assertSame('DONE', $call->fresh()->status);
        $this->assertFalse(collect($this->getJson(route('calls.feed'))->json('calls'))->contains('id', $call->id));
    }

    public function test_company_settings_switches_and_limits(): void
    {
        $t = $this->gulTable('T2');
        $item = Item::where('code', 'MN-001')->value('id');

        $this->setting('qr.max_qty', 5);
        $this->postJson(route('qr.order', $t->qr_token), ['lines' => [['item_id' => $item, 'qty' => 6]]])->assertStatus(422);

        $this->setting('qr.ask_phone', 1);
        $this->postJson(route('qr.order', $t->qr_token), ['lines' => [['item_id' => $item, 'qty' => 1]]])->assertStatus(422)->assertJsonPath('message', 'Please write your mobile number.');
        $this->postJson(route('qr.order', $t->qr_token), ['lines' => [['item_id' => $item, 'qty' => 1]], 'customer_phone' => '01711222333'])->assertOk();

        $this->setting('qr.allow_order', 0);
        $this->postJson(route('qr.order', $t->qr_token), ['lines' => [['item_id' => $item, 'qty' => 1]]])->assertStatus(422);
        $this->get(route('qr.table', $t->qr_token))->assertOk()->assertDontSee('Send to kitchen');

        $this->setting('qr.allow_calls', 0);
        $this->postJson(route('qr.call', $t->qr_token), ['type' => 'BILL'])->assertStatus(422);

        $this->setting('qr.enabled', 0);
        $this->get(route('qr.table', $t->qr_token))->assertStatus(503)->assertSee('not available');
        $this->actingAs($this->u('E-2001'))->get(route('qr.codes'))->assertForbidden();
        $this->get(route('home'))->assertDontSee('Table QR codes');

        // the section in Company settings
        $this->setting('qr.enabled', 1);
        $this->actingAs($this->u('SA-0001'))->get(route('setup.settings'))->assertSee('QR table ordering')->assertSee('Max portions in one QR order');
    }

    public function test_qr_codes_page_new_code_branch_qr_and_bad_codes(): void
    {
        $t = $this->gulTable('T1');
        $gul = Location::where('code', 'BR-GUL')->first();
        $this->actingAs($this->u('E-2001'))->get(route('qr.codes'))->assertOk()->assertSee('/t/'.$t->qr_token, false)->assertSee('/b/'.$gul->qr_token, false)->assertSee('Scan to see the menu');
        $this->get(route('home'))->assertSee('Table QR codes')->assertSee('Table calls');

        // new code: the old sticker stops working
        $old = $t->qr_token;
        $this->post(route('qr.regenerate', $t))->assertSessionHas('ok');
        $this->get('/t/'.$old)->assertNotFound();
        $this->get(route('qr.table', $t->fresh()->qr_token))->assertOk();
        // not for another branch's manager
        $this->actingAs($this->u('E-3001'))->post(route('qr.regenerate', $t))->assertForbidden();
        auth()->logout();

        // branch QR: pick a table
        $this->get(route('qr.branch', $gul->qr_token))->assertOk()->assertSee('Which table')->assertSee('/t/'.$t->fresh()->qr_token, false);
        $this->setting('qr.branch_qr', 0);
        $this->get(route('qr.branch', $gul->qr_token))->assertNotFound();

        // switched-off table and unknown codes
        $t->update(['active' => false]);
        $this->get(route('qr.table', $t->qr_token))->assertNotFound();
        $this->get('/t/doesnotexist1')->assertNotFound();

        // a new table gets its own code automatically
        $nt = DiningTable::create(['location_id' => $gul->id, 'name' => 'T99', 'seats' => 2]);
        $this->assertMatchesRegularExpression('/^[a-z0-9]{12}$/', $nt->qr_token);
    }
}
