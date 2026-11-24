<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\JournalEntry;
use App\Models\PaymentMethod;
use App\Models\PosOrder;
use App\Services\PosService;

/** Offline POS: bills made without network are synced later — once only, at the price charged, into the right shift and ledger. */
class PosOfflineTest extends GroupOpsTestCase
{
    protected function bill(array $extra = []): array
    {
        $item = Item::where('code', 'MN-001')->first();
        $sub = 2 * (float) $item->sale_price;
        $vat = round($sub * 0.05, 2);

        return array_merge([
            'uuid' => 'dev1-'.uniqid(), 'ref' => 'OFF-E2011-261124-001', 'location_id' => $this->u('E-2011')->location_id,
            'order_type' => 'TAKEAWAY', 'customer_phone' => '01711000999', 'discount' => 0, 'vat_pct' => 5, 'sc_pct' => 0,
            'lines' => [['item_id' => $item->id, 'qty' => 2, 'price' => (float) $item->sale_price]],
            'payments' => [['payment_method_id' => PaymentMethod::where('name', 'Cash')->value('id'), 'amount' => $sub + $vat]],
            'created_at' => now()->subMinutes(40)->toIso8601String(), 'paid_at' => now()->subMinutes(35)->toIso8601String(),
        ], $extra);
    }

    public function test_offline_bills_sync_once_into_shift_ledger_and_stock(): void
    {
        $this->actingAs($this->u('E-2011'));
        $this->post(route('pos.open'), ['opening_cash' => 1000]);
        $this->getJson(route('pos.ping'))->assertOk()->assertJsonPath('ok', true)->assertJsonStructure(['csrf']);
        $this->get(route('pos'))->assertOk()->assertSee('pos-offline.js', false)->assertSee('Sync');

        $b1 = $this->bill();
        $b2 = $this->bill(['uuid' => 'dev1-second', 'ref' => 'OFF-E2011-261124-002', 'order_type' => 'DINE_IN', 'dining_table_id' => \App\Models\DiningTable::where('location_id', $this->u('E-2011')->location_id)->value('id'),
            'payments' => [['payment_method_id' => PaymentMethod::where('name', 'bKash')->value('id'), 'amount' => 0], ['payment_method_id' => PaymentMethod::where('name', 'Cash')->value('id'), 'amount' => 1000]]]);
        $res = $this->postJson(route('pos.sync'), ['orders' => [$b1, $b2]])->assertOk()->assertJsonPath('message', '2 of 2 offline bill(s) synced')->json('results');
        $this->assertTrue($res[0]['ok'] && $res[1]['ok']);

        $o = PosOrder::where('client_uuid', $b1['uuid'])->first();
        $this->assertSame('PAID', $o->status);
        $this->assertSame('OFF-E2011-261124-001', $o->offline_ref);
        $this->assertSame(PosService::currentShift($this->u('E-2011'))->id, $o->cashier_shift_id);
        $this->assertEqualsWithDelta(now()->subMinutes(35)->timestamp, $o->paid_at->timestamp, 5);
        $this->assertEqualsWithDelta(now()->subMinutes(40)->timestamp, $o->created_at->timestamp, 5);
        $this->assertSame('SERVED', $o->lines->first()->kot_status); // never pops up on the kitchen screen later
        $this->assertTrue(JournalEntry::where('source_type', 'PosOrder')->where('source_id', $o->id)->exists());
        // cash overpaid on bill 2 → change given back, only the bill is recorded
        $o2 = PosOrder::where('client_uuid', 'dev1-second')->first();
        $this->assertEqualsWithDelta($o2->total, $o2->payments->sum('amount'), 0.01);

        // sending the same bills again (sync retried) never doubles the sale
        $again = $this->postJson(route('pos.sync'), ['orders' => [$b1]])->assertOk()->json('results.0');
        $this->assertTrue($again['ok']);
        $this->assertTrue($again['duplicate']);
        $this->assertSame(1, PosOrder::where('client_uuid', $b1['uuid'])->count());

        // offline sales count in the shift's expected cash
        $shift = PosService::currentShift($this->u('E-2011'));
        $this->post(route('pos.close'), ['counted_cash' => 1000 + $o->total + $o2->total]);
        $this->assertEquals(0.0, (float) $shift->fresh()->difference);
    }

    public function test_offline_bill_keeps_the_price_charged_and_checks_limits(): void
    {
        $this->actingAs($this->u('E-2011'));
        $item = Item::where('code', 'MN-001')->first();
        $charged = (float) $item->sale_price;
        $item->update(['sale_price' => $charged + 50]); // price changed on the server after the bill was printed

        $b = $this->bill(['lines' => [['item_id' => $item->id, 'qty' => 2, 'price' => $charged]], 'payments' => [['payment_method_id' => PaymentMethod::where('name', 'Cash')->value('id'), 'amount' => 2 * $charged * 1.05]]]);
        $this->postJson(route('pos.sync'), ['orders' => [$b]])->assertOk()->assertJsonPath('results.0.ok', true);
        $o = PosOrder::where('client_uuid', $b['uuid'])->first();
        $this->assertEquals(2 * $charged, $o->subtotal);
        $this->assertEquals(round(2 * $charged * 1.05, 2), $o->total);

        // discount above the cashier's 5% limit, short payment, MFS without TxID, other branch → refused, kept on the device
        $L = ['lines' => [['item_id' => $item->id, 'qty' => 2, 'price' => $charged]]];
        $bad = [
            $this->bill($L + ['uuid' => 'x1', 'discount' => 2 * $charged * 0.2]),
            $this->bill($L + ['uuid' => 'x2', 'payments' => [['payment_method_id' => PaymentMethod::where('name', 'Cash')->value('id'), 'amount' => 10]]]),
            $this->bill($L + ['uuid' => 'x3', 'payments' => [['payment_method_id' => PaymentMethod::where('name', 'bKash')->value('id'), 'amount' => 2 * $charged * 1.05]]]),
            $this->bill($L + ['uuid' => 'x4', 'location_id' => \App\Models\Location::where('code', 'BR-DHN')->value('id')]),
        ];
        $res = collect($this->postJson(route('pos.sync'), ['orders' => $bad])->assertOk()->json('results'))->keyBy('uuid');
        $this->assertStringContainsString('above your limit', $res['x1']['error']);
        $this->assertStringContainsString('less than total', $res['x2']['error']);
        $this->assertStringContainsString('transaction ID', $res['x3']['error']);
        $this->assertStringContainsString('own branch', $res['x4']['error']);
        $this->assertSame(0, PosOrder::whereIn('client_uuid', ['x1', 'x2', 'x3', 'x4'])->count());
    }

    public function test_kitchen_order_sent_online_paid_offline(): void
    {
        $this->actingAs($this->u('E-2011'));
        $this->post(route('pos.open'), ['opening_cash' => 0]);
        $o = $this->postJson(route('pos.store'), ['order_type' => 'DINE_IN', 'lines' => [['item_id' => Item::where('code', 'MN-006')->value('id'), 'qty' => 1]]])->json('order');
        $b = $this->bill(['server_order_id' => $o['id'], 'payments' => [['payment_method_id' => PaymentMethod::where('name', 'Cash')->value('id'), 'amount' => 1000]]]);
        $this->postJson(route('pos.sync'), ['orders' => [$b]])->assertOk()->assertJsonPath('results.0.ok', true)->assertJsonPath('results.0.number', $o['number']);
        $this->assertSame('PAID', PosOrder::find($o['id'])->status);
        $this->assertSame(1, PosOrder::where('number', $o['number'])->count());
    }

    public function test_offline_switch_and_permissions(): void
    {
        // not a cashier
        $this->actingAs($this->u('E-2008'))->postJson(route('pos.sync'), ['orders' => [$this->bill()]])->assertForbidden();
        // logged out → 401 (the POS keeps the bills and asks to log in again)
        auth()->logout();
        $this->postJson(route('pos.sync'), ['orders' => [$this->bill()]])->assertUnauthorized();
        // switched off in Company settings
        $this->setting('pos.offline_enabled', 0);
        $this->actingAs($this->u('E-2011'))->postJson(route('pos.sync'), ['orders' => [$this->bill()]])->assertStatus(422);
        $this->actingAs($this->u('SA-0001'))->get(route('setup.settings'))->assertSee('Sync offline POS bills every (minutes)');
    }
}
