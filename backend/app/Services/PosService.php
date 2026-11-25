<?php

namespace App\Services;

use App\Exceptions\BizException;
use App\Models\CashierShift;
use App\Models\Item;
use App\Models\Location;
use App\Models\PaymentMethod;
use App\Models\PosOrder;
use App\Models\PosPayment;
use App\Models\User;
use App\Support\Features;
use Illuminate\Support\Facades\DB;

class PosService
{
    public static function currentShift(User $u): ?CashierShift
    {
        return CashierShift::where('cashier_id', $u->id)->whereNull('closed_at')->latest('id')->first();
    }

    public static function openShift(User $u, float $openingCash, ?int $locationId = null): CashierShift
    {
        if (! $u->hasPerm('pos.sell')) {
            throw new BizException('POS is not enabled for you.');
        }
        if (static::currentShift($u)) {
            throw new BizException('You already have an open shift.');
        }
        $loc = $locationId && $u->isGroupWide() ? $locationId : $u->location_id;
        if (Location::find($loc)?->type !== 'BRANCH') {
            throw new BizException('POS works at branches only.');
        }

        return CashierShift::create(['location_id' => $loc, 'cashier_id' => $u->id, 'opened_at' => now(), 'opening_cash' => $openingCash]);
    }

    /** data: order_type, dining_table_id?, customer_phone?, discount?, lines[[item_id, qty, note?]] */
    public static function createOrder(User $u, array $data): PosOrder
    {
        $shift = static::currentShift($u);
        if (! $shift) {
            throw new BizException('Open your cashier shift first.');
        }
        $lines = array_values(array_filter($data['lines'] ?? [], fn ($l) => ! empty($l['item_id']) && (float) ($l['qty'] ?? 0) > 0));
        if (! $lines) {
            throw new BizException('Add items to the order.');
        }
        // home delivery from the counter: needs the customer's phone + address; it then goes to the delivery board
        $delivery = ($data['order_type'] ?? 'DINE_IN') === 'DELIVERY' && \App\Support\Delivery::on();
        $cust = $delivery ? DeliveryService::customerFields($data) : ['customer_phone' => $data['customer_phone'] ?? null];

        $order = DB::transaction(function () use ($u, $data, $shift, $lines, $delivery, $cust) {
            $o = PosOrder::create(array_merge($cust, [
                'number' => Numbering::next('POS', 'pos_orders'), 'location_id' => $shift->location_id, 'cashier_id' => $u->id,
                'cashier_shift_id' => $shift->id, 'order_type' => $data['order_type'] ?? 'DINE_IN',
                'dining_table_id' => ($data['order_type'] ?? 'DINE_IN') === 'DINE_IN' ? ($data['dining_table_id'] ?? null) : null,
            ], $delivery ? ['source' => 'POS', 'payment_mode' => 'COD', 'delivery_status' => 'COOKING'] : []));
            $sub = 0;
            foreach ($lines as $l) {
                $item = Item::where('type', 'MENU')->findOrFail($l['item_id']);
                $qty = (float) $l['qty'];
                $o->lines()->create(['item_id' => $item->id, 'qty' => $qty, 'price' => $item->sale_price, 'total' => round($qty * $item->sale_price, 2), 'note' => $l['note'] ?? null]);
                $sub += $qty * $item->sale_price;
            }
            static::price($o, $u, $sub, (float) ($data['discount'] ?? 0));

            return $o->fresh('lines.item');
        });
        if ($delivery) {
            DeliveryService::afterPlaced($order, $u);
        }

        return $order;
    }

    public static function price(PosOrder $o, User $u, float $sub, float $discount): void
    {
        $discount = max(0, $discount);
        if ($discount > $sub) {
            throw new BizException('Discount is more than the bill.');
        }
        $pct = $sub > 0 ? $discount / $sub * 100 : 0;
        $limit = $u->is_super_admin ? 100 : (float) ($u->discount_limit ?? 0);
        if ($pct > $limit + 0.001) {
            throw new BizException('Discount '.round($pct, 1).'% is above your limit of '.$limit.'%. Ask the manager.');
        }
        $net = $sub - $discount;
        $sc = round($net * (float) Features::setting('pos.service_charge_percent', 0) / 100, 2);
        $vat = round(($net + $sc) * (float) Features::setting('pos.vat_percent', 5) / 100, 2);
        $o->update(['subtotal' => round($sub, 2), 'discount' => round($discount, 2), 'service_charge' => $sc, 'vat' => $vat, 'total' => round($net + $sc + $vat, 2)]);
    }

    /** payments: [[payment_method_id, amount, reference?]] — cash may exceed (change is given back). */
    public static function pay(PosOrder $o, User $u, array $payments, $at = null): PosOrder
    {
        if ($o->status !== 'OPEN') {
            throw new BizException('Order is already '.strtolower($o->status).'.');
        }
        $payments = array_values(array_filter($payments, fn ($p) => (float) ($p['amount'] ?? 0) > 0));
        $sum = array_sum(array_map(fn ($p) => (float) $p['amount'], $payments));
        if ($sum + 0.009 < $o->total) {
            throw new BizException('Paid ৳'.number_format($sum, 2).' is less than total ৳'.number_format($o->total, 2).'.');
        }
        $change = round($sum - $o->total, 2);
        $at = $at ? \Illuminate\Support\Carbon::parse($at) : now();
        // a QR (table) bill or a bill with no shift is paid into the shift of the cashier who takes the money
        if (($o->source === 'QR' || ! $o->cashier_shift_id) && ($s = static::currentShift($u)) && $s->location_id === $o->location_id) {
            $o->update(['cashier_id' => $u->id, 'cashier_shift_id' => $s->id]);
        }
        DB::transaction(function () use ($o, $u, $payments, $change, $at) {
            $moneyLines = [];
            foreach ($payments as $p) {
                $pm = PaymentMethod::findOrFail($p['payment_method_id']);
                $amt = (float) $p['amount'];
                if ($change > 0 && $pm->type === 'CASH') {
                    $take = min($change, $amt);
                    $amt -= $take;
                    $change -= $take;
                }
                if ($amt <= 0) {
                    continue;
                }
                if ($pm->type === 'MFS' && empty($p['reference'])) {
                    throw new BizException('Enter the '.$pm->name.' transaction ID.');
                }
                PosPayment::create(['pos_order_id' => $o->id, 'payment_method_id' => $pm->id, 'amount' => round($amt, 2), 'reference' => $p['reference'] ?? null]);
                $moneyLines[] = LedgerService::moneyLine(LedgerService::moneyAccountFor($pm->id, $o->location_id), round($amt, 2), 0, $o->number);
            }
            if ($change > 0.009) {
                throw new BizException('Only cash can be overpaid.');
            }
            $cogs = 0;
            if (Features::enabled('pos.recipe_deduction', $o->location_id)) {
                foreach ($o->lines()->with('item.recipe.lines.item')->get() as $l) {
                    $recipe = $l->item->recipe;
                    if (! $recipe) {
                        continue;
                    }
                    foreach ($recipe->lines as $rl) {
                        $q = round($rl->qty * $l->qty / max(0.001, $recipe->yield_qty), 4);
                        StockService::move($o->location_id, $rl->item_id, -$q, 'SALE', $o, null, $o->number);
                        $cogs += $q * $rl->item->last_price;
                    }
                }
            }
            $loc = $o->location_id;
            $lines = array_merge($moneyLines, [
                ['code' => '4000', 'credit' => round($o->subtotal - $o->discount, 2), 'location_id' => $loc],
                ['code' => '4200', 'credit' => $o->service_charge, 'location_id' => $loc],
                ['code' => '2300', 'credit' => $o->vat, 'location_id' => $loc],
            ]);
            if ($cogs > 0) {
                $lines[] = ['code' => '5000', 'debit' => round($cogs, 2), 'location_id' => $loc];
                $lines[] = ['code' => '1200', 'credit' => round($cogs, 2), 'location_id' => $loc];
            }
            LedgerService::post('POS sale '.$o->number, $lines, $o, $loc, $at);
            $o->update(['status' => 'PAID', 'paid_at' => $at] + ($o->delivery_status && $o->delivery_status !== 'DELIVERED' ? ['payment_mode' => 'PREPAID'] : []));
        });

        return $o->fresh('lines.item', 'payments.paymentMethod');
    }

    public static function void(PosOrder $o, User $u, string $reason): PosOrder
    {
        if (! $u->hasPerm('pos.void')) {
            throw new BizException('Only a manager can void orders.');
        }
        if ($o->status !== 'OPEN') {
            throw new BizException('Only unpaid orders can be voided.');
        }
        if (! $reason) {
            throw new BizException('Give a reason.');
        }
        $o->update(['status' => 'VOID', 'void_reason' => $reason]);
        Audit::log('pos.void', $o, null, ['reason' => $reason, 'total' => $o->total]);

        return $o;
    }

    public static function closeShift(CashierShift $s, User $u, float $counted): CashierShift
    {
        if ($s->cashier_id !== $u->id && ! $u->hasPerm('pos.void')) {
            throw new BizException('Not your shift.');
        }
        if ($s->closed_at) {
            throw new BizException('Shift already closed.');
        }
        if (PosOrder::where('cashier_shift_id', $s->id)->where('status', 'OPEN')->whereNull('delivery_status')->exists()) {
            throw new BizException('Settle or void open orders first.');
        }
        // unpaid home deliveries stay on the delivery board; whoever receives the rider's cash settles them
        PosOrder::where('cashier_shift_id', $s->id)->where('status', 'OPEN')->whereNotNull('delivery_status')->update(['cashier_shift_id' => null]);
        $cash = (float) PosPayment::whereHas('order', fn ($q) => $q->where('cashier_shift_id', $s->id)->where('status', 'PAID'))
            ->whereHas('paymentMethod', fn ($q) => $q->where('type', 'CASH'))->sum('amount');
        $expected = round($s->opening_cash + $cash, 2);
        $diff = round($counted - $expected, 2);
        $s->update(['closed_at' => now(), 'expected_cash' => $expected, 'counted_cash' => $counted, 'difference' => $diff]);
        if (abs($diff) >= 1) {
            Notifier::send(array_filter([Location::find($s->location_id)?->manager_id]), 'Cash difference ৳'.number_format($diff, 2), $u->name.' closed shift · expected ৳'.number_format($expected).' counted ৳'.number_format($counted), 'warning', '/pos/shifts');
        }

        return $s;
    }

    public static function daySummary(int $locationId, $date): array
    {
        $orders = PosOrder::where('location_id', $locationId)->where('status', 'PAID')->whereDate('paid_at', $date)->get();
        $byMethod = PosPayment::whereIn('pos_order_id', $orders->pluck('id'))->with('paymentMethod')->get()
            ->groupBy(fn ($p) => $p->paymentMethod->name)->map(fn ($g) => round($g->sum('amount'), 2));

        return [
            'orders' => $orders->count(), 'by_type' => $orders->countBy('order_type')->all(),
            'gross' => round($orders->sum('subtotal'), 2), 'discount' => round($orders->sum('discount'), 2),
            'service_charge' => round($orders->sum('service_charge'), 2), 'vat' => round($orders->sum('vat'), 2),
            'total' => round($orders->sum('total'), 2), 'by_method' => $byMethod->all(),
        ];
    }
}
