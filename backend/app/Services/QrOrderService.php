<?php

namespace App\Services;

use App\Exceptions\BizException;
use App\Models\CashierShift;
use App\Models\DiningTable;
use App\Models\Item;
use App\Models\Location;
use App\Models\PosOrder;
use App\Models\TableCall;
use App\Models\User;
use App\Support\Features;
use Illuminate\Support\Facades\DB;

/**
 * QR table ordering: a customer scans the QR code on their table, orders from the menu
 * (straight to the branch kitchen screen + the POS as an open bill for that table) and can
 * call a waiter / ask for the bill. Cashier, branch manager and floor manager get the alerts.
 */
class QrOrderService
{
    public static function on(): bool
    {
        return (bool) (int) Features::setting('qr.enabled', 1) && Features::enabled('pos.core', null, true);
    }

    public static function table(string $token): DiningTable
    {
        $t = DiningTable::with('location')->where('qr_token', $token)->first();
        if (! $t || ! $t->active || ! $t->location || ! $t->location->active || $t->location->type !== 'BRANCH') {
            abort(404, 'This QR code is not in use. Please ask our staff.');
        }

        return $t;
    }

    public static function branch(string $token): Location
    {
        $l = Location::where('qr_token', $token)->where('type', 'BRANCH')->where('active', true)->first();
        if (! $l || ! (int) Features::setting('qr.branch_qr', 1)) {
            abort(404, 'This QR code is not in use. Please ask our staff.');
        }

        return $l;
    }

    /** Who hears about QR orders and table calls at a branch: branch head, cashiers, floor managers. */
    public static function staffIds(int $locationId)
    {
        return collect([Location::find($locationId)?->manager_id])
            ->merge(Notifier::usersWithPerm('pos.table_calls', $locationId)->pluck('id'))->filter()->unique()->values();
    }

    /** The bill a QR order belongs to: the cashier on duty at the branch (else the branch head). */
    protected static function owner(int $locationId): array
    {
        $shift = CashierShift::where('location_id', $locationId)->whereNull('closed_at')->latest('id')->first();
        if ($shift) {
            return [$shift->cashier_id, $shift->id];
        }
        $uid = Location::find($locationId)?->manager_id
            ?? Notifier::usersWithPerm('pos.sell', $locationId)->first()?->id
            ?? throw new BizException('Ordering is not available at this branch right now. Please ask our staff.');

        return [$uid, null];
    }

    /** data: lines[[item_id, qty, note?]], customer_name?, customer_phone?, note? */
    public static function order(DiningTable $t, array $data): PosOrder
    {
        if (! static::on() || ! (int) Features::setting('qr.allow_order', 1)) {
            throw new BizException('Ordering from the table is switched off. Please call a waiter.');
        }
        $lines = array_values(array_filter((array) ($data['lines'] ?? []), fn ($l) => ! empty($l['item_id']) && (float) ($l['qty'] ?? 0) > 0));
        if (! $lines) {
            throw new BizException('Choose something from the menu first.');
        }
        $qty = array_sum(array_map(fn ($l) => (int) ceil((float) $l['qty']), $lines));
        $max = max(1, (int) Features::setting('qr.max_qty', 30));
        if ($qty > $max) {
            throw new BizException('That is a big order! Please ask a waiter to take orders of more than '.$max.' portions.');
        }
        $phone = trim((string) ($data['customer_phone'] ?? ''));
        if ((int) Features::setting('qr.ask_phone', 0) && ! preg_match('/^\+?[0-9 \-]{10,15}$/', $phone)) {
            throw new BizException('Please write your mobile number.');
        }
        $note = trim(mb_substr((string) ($data['note'] ?? ''), 0, 200));

        $o = DB::transaction(function () use ($t, $lines, $phone, $note, $data) {
            // one open bill per table: more orders from the same table are added to it
            $o = PosOrder::where('dining_table_id', $t->id)->where('status', 'OPEN')->where('order_type', 'DINE_IN')
                ->where('created_at', '>=', now()->subHours(8))->lockForUpdate()->latest('id')->first();
            if (! $o) {
                [$owner, $shiftId] = static::owner($t->location_id);
                $o = PosOrder::create([
                    'number' => Numbering::next('POS', 'pos_orders'), 'location_id' => $t->location_id, 'cashier_id' => $owner, 'cashier_shift_id' => $shiftId,
                    'order_type' => 'DINE_IN', 'source' => 'QR', 'dining_table_id' => $t->id,
                    'customer_phone' => $phone ?: null, 'customer_name' => trim(mb_substr((string) ($data['customer_name'] ?? ''), 0, 60)) ?: null,
                ]);
            }
            $add = 0;
            foreach ($lines as $i => $l) {
                $item = Item::where('type', 'MENU')->where('active', true)->find((int) $l['item_id']);
                if (! $item) {
                    throw new BizException('Something on your list is no longer on the menu. Please refresh the page.');
                }
                $q = max(1, (int) ceil((float) $l['qty']));
                $lineNote = trim(mb_substr((string) ($l['note'] ?? ''), 0, 120));
                if ($i === 0 && $note !== '') {
                    $lineNote = trim('📱 '.$note.($lineNote ? ' · '.$lineNote : ''));
                }
                $o->lines()->create(['item_id' => $item->id, 'qty' => $q, 'price' => $item->sale_price, 'total' => round($q * $item->sale_price, 2), 'note' => $lineNote ?: null, 'kot_status' => 'NEW']);
                $add += $q * $item->sale_price;
            }
            PosService::price($o, $o->cashier ?? User::find($o->cashier_id), round($o->subtotal + $add, 2), (float) $o->discount);

            return $o->fresh(['lines.item', 'table', 'location']);
        });

        $items = collect($lines)->map(fn ($l) => (int) ceil((float) $l['qty']).'× '.(Item::find($l['item_id'])?->name))->implode(', ');
        Notifier::send(static::staffIds($t->location_id), '📱 QR order · Table '.$t->name, $items.' · bill ৳'.number_format($o->total).' ('.$o->number.')', 'warning', '/pos');

        return $o;
    }

    /** "Call waiter", "Bill please", … — one open call per type per table (no spam). */
    public static function call(DiningTable $t, string $type, ?string $note = null): TableCall
    {
        if (! static::on() || ! (int) Features::setting('qr.allow_calls', 1)) {
            throw new BizException('Please wave to our staff.');
        }
        if (! array_key_exists($type, TableCall::TYPES)) {
            throw new BizException('Unknown request.');
        }
        $open = TableCall::where('dining_table_id', $t->id)->where('type', $type)->whereIn('status', ['OPEN', 'COMING'])->where('created_at', '>=', now()->subMinutes(20))->latest('id')->first();
        if ($open) {
            return $open;
        }
        $c = TableCall::create(['location_id' => $t->location_id, 'dining_table_id' => $t->id, 'type' => $type, 'note' => trim(mb_substr((string) $note, 0, 200)) ?: null]);
        Notifier::send(static::staffIds($t->location_id), TableCall::TYPES[$type][0].' Table '.$t->name.': '.TableCall::TYPES[$type][1], $c->note ?: $t->location->name, 'warning', '/table-calls');

        return $c;
    }

    public static function canAnswer(User $u, int $locationId): bool
    {
        return $u->hasPerm('pos.table_calls') && ($u->isGroupWide() || $u->location_id === $locationId);
    }

    public static function answer(TableCall $c, User $u, string $what): TableCall
    {
        if (! static::canAnswer($u, $c->location_id)) {
            throw new BizException('This table is not in your branch.');
        }
        match ($what) {
            'coming' => $c->status === 'OPEN' ? $c->update(['status' => 'COMING', 'answered_by' => $u->id, 'answered_at' => now()]) : null,
            'done' => $c->update(['status' => 'DONE', 'answered_by' => $c->answered_by ?? $u->id, 'answered_at' => $c->answered_at ?? now(), 'done_at' => now()]),
            default => throw new BizException('Unknown action.'),
        };

        return $c->fresh();
    }

    /** What the staff screens show: open calls + QR bills not paid yet at these branches. */
    public static function feed(array $locationIds): array
    {
        $calls = TableCall::with('table', 'answeredBy', 'location')->whereIn('location_id', $locationIds)->whereIn('status', ['OPEN', 'COMING'])
            ->where('created_at', '>=', now()->subHours(6))->orderBy('id')->get()
            ->map(fn ($c) => ['id' => $c->id, 'table' => $c->table?->name, 'branch' => $c->location?->name, 'type' => $c->type, 'label' => $c->label(), 'note' => $c->note,
                'status' => $c->status, 'by' => $c->answeredBy?->name, 'at' => $c->created_at->format('h:i A'), 'mins' => (int) $c->created_at->diffInMinutes(now())]);
        $orders = PosOrder::with('lines.item', 'table', 'location')->whereIn('location_id', $locationIds)->where('source', 'QR')->where('status', 'OPEN')
            ->where('created_at', '>=', now()->subHours(8))->orderBy('id')->get();

        return [
            'calls' => $calls->values(),
            'orders' => $orders->map(fn ($o) => ['id' => $o->id, 'number' => $o->number, 'table' => $o->table?->name, 'branch' => $o->location?->name, 'total' => (float) $o->total,
                'at' => $o->created_at->format('h:i A'), 'items' => $o->lines->map(fn ($l) => ['name' => $l->item->name, 'qty' => (float) $l->qty, 'kot' => $l->kot_status, 'note' => $l->note]), 'order' => $o])->values(),
        ];
    }
}
