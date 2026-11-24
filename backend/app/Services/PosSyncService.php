<?php

namespace App\Services;

use App\Exceptions\BizException;
use App\Models\CashierShift;
use App\Models\Item;
use App\Models\Location;
use App\Models\PaymentMethod;
use App\Models\PosOrder;
use App\Models\User;
use App\Support\Features;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Offline POS: bills made on a POS computer while the network / server was down are kept in the
 * browser and sent here later (every few minutes, or with the Sync button). Each bill carries a
 * device id (client_uuid) so sending it twice never makes a second sale.
 */
class PosSyncService
{
    /** @return array<int, array{uuid:string, ok:bool, number?:string, error?:string, duplicate?:bool}> */
    public static function sync(User $u, array $orders): array
    {
        if (! (int) Features::setting('pos.offline_enabled', 1)) {
            throw new BizException('Offline POS is switched off in Company settings.');
        }
        if (! $u->hasPerm('pos.sell')) {
            throw new BizException('POS is not enabled for you.');
        }
        $out = [];
        foreach (array_slice($orders, 0, 200) as $data) {
            $uuid = substr((string) ($data['uuid'] ?? ''), 0, 64);
            if ($uuid === '') {
                $out[] = ['uuid' => '', 'ok' => false, 'error' => 'Missing bill id.'];
                continue;
            }
            try {
                $out[] = ['uuid' => $uuid, 'ok' => true] + static::one($u, $uuid, $data);
            } catch (BizException|\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
                $out[] = ['uuid' => $uuid, 'ok' => false, 'error' => $e instanceof BizException ? $e->getMessage() : 'An item or payment method no longer exists.'];
            }
        }

        return $out;
    }

    protected static function time($v, Carbon $fallback): Carbon
    {
        try {
            $t = $v ? Carbon::parse($v)->setTimezone(config('app.timezone')) : $fallback;
        } catch (\Throwable) {
            $t = $fallback;
        }
        // a wrong PC clock must not put sales in the future or far in the past
        if ($t->gt(now()->addMinutes(5))) {
            return now();
        }

        return $t->lt(now()->subDays(30)) ? now() : $t;
    }

    protected static function one(User $u, string $uuid, array $d): array
    {
        if ($done = PosOrder::where('client_uuid', $uuid)->first()) {
            return ['number' => $done->number, 'duplicate' => true];
        }
        $paidAt = static::time($d['paid_at'] ?? null, now());
        $createdAt = static::time($d['created_at'] ?? null, $paidAt)->min($paidAt);
        $payments = array_values(array_filter((array) ($d['payments'] ?? []), fn ($p) => (float) ($p['amount'] ?? 0) > 0));
        if (! $payments) {
            throw new BizException('The bill has no payment.');
        }

        // a server bill (sent to the kitchen while online) that was paid while offline
        if (! empty($d['server_order_id'])) {
            $o = PosOrder::findOrFail((int) $d['server_order_id']);
            if ($o->cashier_id !== $u->id && ! ($u->hasPerm('pos.void') && $o->location_id === $u->location_id) && ! $u->isGroupWide()) {
                throw new BizException('This bill belongs to another cashier.');
            }
            if ($o->status !== 'OPEN') {
                throw new BizException('Bill '.$o->number.' is already '.strtolower($o->status).'.');
            }
            return DB::transaction(function () use ($o, $u, $payments, $paidAt, $uuid, $d) {
                $o->update(['client_uuid' => $uuid, 'offline_ref' => substr((string) ($d['ref'] ?? ''), 0, 40) ?: null, 'synced_at' => now()]);
                PosService::pay($o->fresh(), $u, $payments, $paidAt);

                return ['number' => $o->number];
            });
        }

        $loc = Location::find((int) ($d['location_id'] ?? 0));
        if (! $loc || $loc->type !== 'BRANCH') {
            throw new BizException('Unknown branch.');
        }
        if (! $u->isGroupWide() && $loc->id !== $u->location_id) {
            throw new BizException('You can only sync bills of your own branch.');
        }
        $lines = array_values(array_filter((array) ($d['lines'] ?? []), fn ($l) => ! empty($l['item_id']) && (float) ($l['qty'] ?? 0) > 0));
        if (! $lines) {
            throw new BizException('The bill has no items.');
        }
        $type = in_array($d['order_type'] ?? '', ['DINE_IN', 'TAKEAWAY', 'DELIVERY']) ? $d['order_type'] : 'TAKEAWAY';

        return DB::transaction(function () use ($u, $uuid, $d, $loc, $lines, $type, $createdAt, $paidAt, $payments) {
            $shift = CashierShift::where('cashier_id', $u->id)->where('location_id', $loc->id)->where('opened_at', '<=', $paidAt)
                ->where(fn ($w) => $w->whereNull('closed_at')->orWhere('closed_at', '>=', $paidAt))->latest('id')->first()
                ?? CashierShift::where('cashier_id', $u->id)->where('location_id', $loc->id)->whereNull('closed_at')->latest('id')->first();
            $table = $type === 'DINE_IN' && ! empty($d['dining_table_id']) ? \App\Models\DiningTable::where('location_id', $loc->id)->find((int) $d['dining_table_id']) : null;
            $o = PosOrder::create([
                'number' => Numbering::next('POS', 'pos_orders'), 'client_uuid' => $uuid, 'offline_ref' => substr((string) ($d['ref'] ?? ''), 0, 40) ?: null,
                'location_id' => $loc->id, 'cashier_id' => $u->id, 'cashier_shift_id' => $shift?->id,
                'order_type' => $type, 'dining_table_id' => $table?->id, 'customer_phone' => substr((string) ($d['customer_phone'] ?? ''), 0, 30) ?: null,
                'synced_at' => now(), 'created_at' => $createdAt, 'updated_at' => now(),
            ]);
            // the price the customer was charged on the offline bill
            $sub = 0;
            foreach ($lines as $l) {
                $item = Item::where('type', 'MENU')->findOrFail((int) $l['item_id']);
                $qty = round((float) $l['qty'], 2);
                $price = isset($l['price']) && is_numeric($l['price']) && (float) $l['price'] >= 0 ? round((float) $l['price'], 2) : (float) $item->sale_price;
                $o->lines()->create(['item_id' => $item->id, 'qty' => $qty, 'price' => $price, 'total' => round($qty * $price, 2), 'note' => $l['note'] ?? null, 'kot_status' => 'SERVED']);
                $sub += $qty * $price;
            }
            $discount = max(0, (float) ($d['discount'] ?? 0));
            if ($discount > $sub) {
                throw new BizException('Discount is more than the bill.');
            }
            $pct = $sub > 0 ? $discount / $sub * 100 : 0;
            $limit = $u->is_super_admin ? 100 : (float) ($u->discount_limit ?? 0);
            if ($pct > $limit + 0.001) {
                throw new BizException('Discount '.round($pct, 1).'% is above your limit of '.$limit.'%.');
            }
            // VAT / service charge with the rates the POS had when the bill was made (kept within sane bounds)
            $vatPct = min(30, max(0, (float) ($d['vat_pct'] ?? Features::setting('pos.vat_percent', 5))));
            $scPct = min(30, max(0, (float) ($d['sc_pct'] ?? Features::setting('pos.service_charge_percent', 0))));
            $net = $sub - $discount;
            $sc = round($net * $scPct / 100, 2);
            $vat = round(($net + $sc) * $vatPct / 100, 2);
            $o->update(['subtotal' => round($sub, 2), 'discount' => round($discount, 2), 'service_charge' => $sc, 'vat' => $vat, 'total' => round($net + $sc + $vat, 2)]);
            PosService::pay($o->fresh(), $u, $payments, $paidAt);

            return ['number' => $o->number];
        });
    }
}
