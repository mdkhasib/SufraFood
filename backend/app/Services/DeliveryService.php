<?php

namespace App\Services;

use App\Exceptions\BizException;
use App\Models\Item;
use App\Models\Location;
use App\Models\PaymentMethod;
use App\Models\PosOrder;
use App\Models\Task;
use App\Models\User;
use App\Support\Delivery;
use App\Support\Features;
use Illuminate\Support\Facades\DB;

/**
 * Home delivery: order (branch POS or online team) → branch kitchen → manager gives it to a rider
 * → rider collects from the kitchen → delivers → hands the cash to the branch. Each delivery earns
 * the rider the branch's per-delivery rate, paid with the salary.
 */
class DeliveryService
{
    protected static function ensureOn(): void
    {
        if (! Delivery::on()) {
            throw new BizException('Home delivery is switched off in Company settings.');
        }
    }

    /** Validate and copy the customer part of a delivery order. */
    public static function customerFields(array $d): array
    {
        $phone = trim((string) ($d['customer_phone'] ?? ''));
        $addr = trim((string) ($d['delivery_address'] ?? ''));
        if ($phone === '' || $addr === '') {
            throw new BizException('A delivery order needs the customer’s phone number and address.');
        }

        return [
            'customer_phone' => $phone, 'customer_name' => trim((string) ($d['customer_name'] ?? '')) ?: null,
            'delivery_address' => $addr, 'delivery_area' => trim((string) ($d['delivery_area'] ?? '')) ?: null,
            'delivery_note' => trim((string) ($d['delivery_note'] ?? '')) ?: null,
        ];
    }

    /**
     * The online / call-centre team (or a branch manager) places a delivery order for a branch.
     * data: location_id, customer_*, delivery_*, discount?, lines[[item_id, qty, note?]], payment_mode COD|PREPAID, payment_method_id?, reference?
     */
    public static function place(User $by, array $data): PosOrder
    {
        static::ensureOn();
        $locId = (int) ($data['location_id'] ?? 0) ?: $by->location_id;
        $loc = Location::find($locId);
        if (! $loc || $loc->type !== 'BRANCH' || ! $loc->active) {
            throw new BizException('Choose the branch that will cook and deliver it.');
        }
        $online = $by->hasPerm('delivery.order');
        if (! $online && ! Delivery::canManage($by, $loc->id) && ! ($by->hasPerm('pos.sell') && $by->location_id === $loc->id)) {
            throw new BizException('You cannot take delivery orders for this branch.');
        }
        $lines = array_values(array_filter($data['lines'] ?? [], fn ($l) => ! empty($l['item_id']) && (float) ($l['qty'] ?? 0) > 0));
        if (! $lines) {
            throw new BizException('Add items to the order.');
        }
        $cust = static::customerFields($data);
        $mode = ($data['payment_mode'] ?? 'COD') === 'PREPAID' ? 'PREPAID' : 'COD';

        $o = DB::transaction(function () use ($by, $loc, $lines, $cust, $mode, $data, $online) {
            $o = PosOrder::create(array_merge($cust, [
                'number' => Numbering::next('POS', 'pos_orders'), 'location_id' => $loc->id, 'cashier_id' => $by->id,
                'cashier_shift_id' => $by->location_id === $loc->id ? PosService::currentShift($by)?->id : null,
                'order_type' => 'DELIVERY', 'source' => $online ? 'ONLINE' : 'POS',
                'payment_mode' => $mode, 'delivery_status' => 'COOKING',
            ]));
            $sub = 0;
            foreach ($lines as $l) {
                $item = Item::where('type', 'MENU')->where('active', true)->findOrFail($l['item_id']);
                $qty = (float) $l['qty'];
                $o->lines()->create(['item_id' => $item->id, 'qty' => $qty, 'price' => $item->sale_price, 'total' => round($qty * $item->sale_price, 2), 'note' => $l['note'] ?? null]);
                $sub += $qty * $item->sale_price;
            }
            PosService::price($o, $by, $sub, (float) ($data['discount'] ?? 0));
            if ($mode === 'PREPAID') {
                $pm = PaymentMethod::where('active', true)->find($data['payment_method_id'] ?? 0);
                if (! $pm || $pm->type === 'CASH') {
                    throw new BizException('For a prepaid order choose how the customer paid (bKash, Nagad, card…).');
                }
                PosService::pay($o->fresh(), $by, [['payment_method_id' => $pm->id, 'amount' => $o->fresh()->total, 'reference' => $data['reference'] ?? null]]);
            }

            return $o->fresh('lines.item', 'location');
        });
        static::afterPlaced($o, $by);

        return $o;
    }

    /** Tell the branch: a new delivery is in the kitchen and needs a rider. */
    public static function afterPlaced(PosOrder $o, User $by): void
    {
        $to = static::branchManagers($o->location_id);
        $who = $o->source === 'ONLINE' ? 'Online order' : 'Counter order';
        Notifier::send($to->reject(fn ($id) => $id === $by->id), '🛵 New home delivery '.$o->number, $who.' · '.($o->customer_name ?: $o->customer_phone).' · '.($o->delivery_area ?: $o->delivery_address).' · ৳'.number_format($o->total).' · give it to a rider', 'warning', '/home-delivery');
    }

    /** Branch head + everyone at the branch who can manage deliveries. */
    public static function branchManagers(int $locationId)
    {
        return collect([Location::find($locationId)?->manager_id])
            ->merge(Notifier::usersWithPerm('delivery.manage', $locationId)->pluck('id'))->filter()->unique()->values();
    }

    /** Manager gives the delivery to a rider: the rider gets a task with the customer's details. */
    public static function assign(PosOrder $o, User $by, int $riderId): PosOrder
    {
        static::ensureOn();
        if (! $o->isDelivery()) {
            throw new BizException('This is not a home delivery order.');
        }
        if (! Delivery::canAssign($by, $o->location_id)) {
            throw new BizException('Only the branch manager or the cashier can give deliveries to riders.');
        }
        if (! in_array($o->delivery_status, ['COOKING', 'READY'])) {
            throw new BizException('This delivery is already '.strtolower(str_replace('_', ' ', $o->delivery_status)).'.');
        }
        $rider = Delivery::riders($o->location_id)->firstWhere('id', $riderId);
        if (! $rider) {
            throw new BizException('Choose a rider from this branch (staff marked “can deliver”).');
        }
        if ($o->rider_id === $rider->id) {
            return $o;
        }

        return DB::transaction(function () use ($o, $by, $rider) {
            if ($o->rider_id && $o->delivery_task_id) {
                Task::where('id', $o->delivery_task_id)->whereIn('status', ['ASSIGNED', 'STARTED'])->update(['status' => 'CANCELLED']);
                Notifier::send($o->rider_id, 'Delivery '.$o->number.' moved to another rider', 'You do not need to deliver it.', 'info', '/tasks');
            }
            $o->load('lines.item');
            $items = $o->lines->map(fn ($l) => rtrim(rtrim(number_format($l->qty, 2), '0'), '.').' × '.$l->item->name)->implode(', ');
            $collect = $o->toCollect();
            $desc = implode("\n", array_filter([
                'Customer: '.($o->customer_name ?: '-').' · '.$o->customer_phone,
                'Address: '.$o->delivery_address.($o->delivery_area ? ' ('.$o->delivery_area.')' : ''),
                $o->delivery_note ? 'Note: '.$o->delivery_note : null,
                'Items: '.$items,
                $collect > 0 ? 'Collect cash: ৳'.number_format($collect, 2) : 'Already paid · collect nothing',
            ]));
            $title = 'Deliver '.$o->number.' to '.($o->customer_name ?: $o->customer_phone).($o->delivery_area ? ' · '.$o->delivery_area : '');
            $task = Task::create([
                'title' => $title, 'title_bn' => 'ডেলিভারি '.$o->number.' · '.($o->customer_name ?: $o->customer_phone),
                'description' => $desc, 'assigned_by' => $by->id, 'assignee_id' => $rider->id, 'location_id' => $o->location_id,
                'priority' => 'HIGH', 'due_at' => now()->addMinutes(max(10, (int) Features::setting('delivery.target_minutes', 45))),
                'linked_type' => 'PosOrder', 'linked_id' => $o->id, 'notify_on_done' => true,
            ]);
            $o->update(['rider_id' => $rider->id, 'rider_assigned_by' => $by->id, 'rider_assigned_at' => now(), 'delivery_task_id' => $task->id]);
            $ready = $o->delivery_status === 'READY';
            Notifier::send($rider, '🛵 Delivery for you: '.$o->number, ($ready ? 'Food is READY — collect it from the kitchen now.' : 'Kitchen is preparing it — you will be told when it is ready.').' · '.($o->delivery_area ?: $o->delivery_address).($collect > 0 ? ' · collect ৳'.number_format($collect) : ' · paid'), 'warning', '/tasks');

            return $o->fresh();
        });
    }

    /** Called when the kitchen marks the last item ready. */
    public static function kitchenReady(PosOrder $o): void
    {
        if (! $o->isDelivery() || $o->delivery_status !== 'COOKING' || $o->lines()->where('kot_status', 'NEW')->exists()) {
            return;
        }
        $o->update(['delivery_status' => 'READY', 'ready_at' => now()]);
        if ($o->rider_id) {
            Notifier::send($o->rider_id, '✅ Food ready: '.$o->number, 'Collect it from the kitchen and go · '.($o->delivery_area ?: $o->delivery_address), 'warning', '/tasks');
        } else {
            Notifier::send(static::branchManagers($o->location_id), 'Delivery '.$o->number.' is ready but has no rider', 'Give it to a rider now.', 'warning', '/home-delivery');
        }
    }

    protected static function ensureRider(PosOrder $o, User $u): void
    {
        if ($o->rider_id !== $u->id && ! $u->is_super_admin) {
            throw new BizException('This delivery is not given to you.');
        }
    }

    /** Rider collects the food from the kitchen. */
    public static function pickup(PosOrder $o, User $rider): PosOrder
    {
        static::ensureRider($o, $rider);
        if ($o->delivery_status === 'COOKING') {
            if ((int) Features::setting('delivery.need_kitchen_ready', 1) && Features::enabled('pos.kds', $o->location_id, true)) {
                throw new BizException('The kitchen has not marked it ready yet. Wait at the kitchen counter.');
            }
        } elseif ($o->delivery_status !== 'READY') {
            throw new BizException('This delivery is already '.strtolower(str_replace('_', ' ', $o->delivery_status)).'.');
        }
        DB::transaction(function () use ($o) {
            $o->lines()->update(['kot_status' => 'SERVED']);
            $o->update(['delivery_status' => 'PICKED_UP', 'picked_up_at' => now(), 'ready_at' => $o->ready_at ?? now()]);
            Task::where('id', $o->delivery_task_id)->where('status', 'ASSIGNED')->update(['status' => 'STARTED', 'started_at' => now()]);
        });
        if ($o->source === 'ONLINE' && $o->cashier_id !== $rider->id) {
            Notifier::send($o->cashier_id, 'On the way: '.$o->number, $rider->name.' picked it up at '.now()->format('h:i A'), 'info', '/home-delivery');
        }

        return $o->fresh();
    }

    /** Rider hands over the food; for cash-on-delivery the cash collected is recorded (handed to the branch later). */
    public static function deliver(PosOrder $o, User $rider, ?float $cash = null, ?string $note = null): PosOrder
    {
        static::ensureRider($o, $rider);
        if ($o->delivery_status === 'READY' || ($o->delivery_status === 'COOKING' && ! (int) Features::setting('delivery.need_kitchen_ready', 1))) {
            $o = static::pickup($o, $rider);
        }
        if ($o->delivery_status !== 'PICKED_UP') {
            throw new BizException($o->delivery_status === 'COOKING' ? 'Collect the food from the kitchen first.' : 'This delivery is already '.strtolower(str_replace('_', ' ', $o->delivery_status)).'.');
        }
        $due = $o->toCollect();
        $cash = $due > 0 ? ($cash === null ? $due : max(0, $cash)) : 0;
        if ($due > 0 && $cash + 0.009 < $due && ! $note) {
            throw new BizException('Collected ৳'.number_format($cash, 2).' is less than the bill ৳'.number_format($due, 2).'. Write a note why.');
        }
        DB::transaction(function () use ($o, $cash, $note) {
            $o->update(['delivery_status' => 'DELIVERED', 'delivered_at' => now(), 'cash_collected' => $cash, 'rider_fee' => Delivery::rate($o->location),
                'delivery_note' => $note ? trim(($o->delivery_note ? $o->delivery_note.' · ' : '').'Rider: '.$note) : $o->delivery_note]);
            $t = Task::find($o->delivery_task_id);
            if ($t && in_array($t->status, ['ASSIGNED', 'STARTED'])) {
                $t->update(['status' => 'DONE', 'started_at' => $t->started_at ?? $o->picked_up_at, 'submitted_at' => now(), 'completed_at' => now(), 'proof_note' => $note]);
            }
        });
        $o = $o->fresh();
        $mins = $o->rider_assigned_at ? (int) $o->rider_assigned_at->diffInMinutes($o->delivered_at) : null;
        $body = $rider->name.' · '.now()->format('h:i A').($mins !== null ? ' · '.$mins.' min' : '').($cash > 0 ? ' · has ৳'.number_format($cash, 2).' cash to hand over' : ' · prepaid');
        Notifier::send(static::branchManagers($o->location_id)->push($o->cashier_id)->unique()->reject(fn ($id) => $id === $rider->id), '✓ Delivered '.$o->number, $body, 'success', '/home-delivery');

        return $o;
    }

    /** The manager / cashier receives the rider's cash: the bill is paid into this branch's cash. */
    public static function receiveCash(PosOrder $o, User $by, ?float $amount = null): PosOrder
    {
        static::ensureOn();
        if (! Delivery::canReceiveCash($by, $o->location_id)) {
            throw new BizException('Only the branch manager or cashier can receive the rider’s cash.');
        }
        if ($o->delivery_status !== 'DELIVERED') {
            throw new BizException('Receive the cash after the rider has delivered it.');
        }
        if ($o->status !== 'OPEN') {
            throw new BizException('This bill is already '.strtolower($o->status).'.');
        }
        $cash = PaymentMethod::where('type', 'CASH')->where('active', true)->orderBy('id')->first() ?? throw new BizException('No cash payment method.');
        $shift = PosService::currentShift($by);
        if ($shift && $shift->location_id === $o->location_id) {
            $o->update(['cashier_shift_id' => $shift->id]);
        }

        return PosService::pay($o->fresh(), $by, [['payment_method_id' => $cash->id, 'amount' => $amount ?: $o->total]]);
    }

    /** Cancel before delivery (unpaid orders only). */
    public static function cancel(PosOrder $o, User $by, string $reason): PosOrder
    {
        $mine = $o->cashier_id === $by->id;
        if (! Delivery::canManage($by, $o->location_id) && ! $mine) {
            throw new BizException('Only the branch manager or the person who took the order can cancel it.');
        }
        if (! in_array($o->delivery_status, ['COOKING', 'READY', 'PICKED_UP'])) {
            throw new BizException('It cannot be cancelled now.');
        }
        if ($o->status !== 'OPEN') {
            throw new BizException('This order is already paid. Ask Accounts for a refund.');
        }
        if (trim($reason) === '') {
            throw new BizException('Give a reason.');
        }
        DB::transaction(function () use ($o, $reason) {
            $o->update(['status' => 'VOID', 'void_reason' => $reason, 'delivery_status' => 'CANCELLED']);
            Task::where('id', $o->delivery_task_id)->whereIn('status', ['ASSIGNED', 'STARTED'])->update(['status' => 'CANCELLED']);
        });
        Audit::log('delivery.cancel', $o, null, ['reason' => $reason, 'total' => $o->total]);
        if ($o->rider_id) {
            Notifier::send($o->rider_id, 'Delivery cancelled: '.$o->number, $reason, 'warning', '/tasks');
        }

        return $o->fresh();
    }

    /** Riders' deliveries and bonus for a month (also what payroll uses). */
    public static function riderStats(string $month, ?int $locationId = null)
    {
        $start = \Illuminate\Support\Carbon::parse($month.'-01')->startOfMonth();

        return PosOrder::with('rider.location')->where('delivery_status', 'DELIVERED')->whereNotNull('rider_id')
            ->whereBetween('delivered_at', [$start, $start->copy()->endOfMonth()])
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId))->get()
            ->groupBy('rider_id')->map(fn ($g) => [
                'rider' => $g->first()->rider, 'count' => $g->count(), 'bonus' => round($g->sum('rider_fee'), 2),
                'cash' => round($g->sum('cash_collected'), 2),
                'avg_min' => (int) round($g->filter(fn ($o) => $o->rider_assigned_at)->avg(fn ($o) => $o->rider_assigned_at->diffInMinutes($o->delivered_at)) ?? 0),
            ])->sortByDesc('count')->values();
    }

    /** Deliveries done by a rider in a payroll month: [count, bonus ৳]. */
    public static function payrollFor(int $userId, \Illuminate\Support\Carbon $start): array
    {
        $q = PosOrder::where('rider_id', $userId)->where('delivery_status', 'DELIVERED')->whereBetween('delivered_at', [$start->copy()->startOfMonth(), $start->copy()->endOfMonth()]);

        return [(int) (clone $q)->count(), round((float) $q->sum('rider_fee'), 2)];
    }
}
