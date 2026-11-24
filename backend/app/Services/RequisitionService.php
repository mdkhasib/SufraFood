<?php

namespace App\Services;

use App\Exceptions\BizException;
use App\Models\Dispatch;
use App\Models\Item;
use App\Models\Location;
use App\Models\Requisition;
use App\Models\RequisitionHistory;
use App\Models\StandingOrder;
use App\Models\User;
use App\Support\Features;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RequisitionService
{
    public static function fulfilLocationFor(string $type, ?int $toLocationId = null): Location
    {
        if ($type === 'TRANSFER') {
            $loc = Location::find($toLocationId);
            if (! $loc) {
                throw new BizException('Choose the branch to transfer from.');
            }

            return $loc;
        }
        $locType = config("groupops.requisition_types.$type.2");
        $loc = Location::where('type', $locType)->where('active', true)->orderBy('id')->first();
        if (! $loc) {
            throw new BizException("No $locType location set up.");
        }

        return $loc;
    }

    protected static function history(Requisition $r, ?string $from, string $to, ?User $u, ?string $note = null): void
    {
        RequisitionHistory::create(['requisition_id' => $r->id, 'from_status' => $from, 'to_status' => $to, 'user_id' => $u?->id, 'note' => $note]);
    }

    public static function setStatus(Requisition $r, string $to, ?User $u, ?string $note = null, array $extra = []): void
    {
        $from = $r->status;
        $r->update(array_merge(['status' => $to], $extra));
        static::history($r, $from, $to, $u, $note);
    }

    public static function canCreate(User $u, string $type): bool
    {
        $perm = config("groupops.requisition_types.$type.1");

        return $perm && $u->hasPerm($perm);
    }

    /**
     * data: type, lines[[item_id, qty, note?]], needed_by, priority?, notes?, to_location_id?, from_location_id?, description (repair), parent_ids[]
     */
    public static function create(User $u, array $data, bool $submit = true, string $source = 'MANUAL'): Requisition
    {
        $type = $data['type'] ?? '';
        if (! array_key_exists($type, config('groupops.requisition_types'))) {
            throw new BizException('Unknown request type.');
        }
        if ($source === 'MANUAL' && ! static::canCreate($u, $type)) {
            throw new BizException('You are not allowed to raise this request.');
        }
        $fromId = ($u->isGroupWide() && ! empty($data['from_location_id'])) ? (int) $data['from_location_id'] : ($data['force_from_location_id'] ?? $u->location_id);
        $to = static::fulfilLocationFor($type, $data['to_location_id'] ?? null);
        if ($to->id === $fromId && $type !== 'PURCHASE' && $type !== 'REPAIR') {
            throw new BizException('A location cannot request from itself.');
        }
        $lines = array_values(array_filter($data['lines'] ?? [], fn ($l) => ! empty($l['qty']) && (float) $l['qty'] > 0 && (! empty($l['item_id']) || ! empty($l['description']))));
        if (! $lines) {
            throw new BizException('Add at least one item with a quantity.');
        }
        $neededBy = ! empty($data['needed_by']) ? \App\Support\Dt::parse($data['needed_by']) : now()->addDay();
        $priority = $data['priority'] ?? 'NORMAL';
        if ($type === 'FOOD_ORDER' && $source !== 'STANDING') {
            $cutoff = Carbon::parse($neededBy->copy()->subDay()->toDateString().' '.Features::setting('orders.cutoff_time', '20:00'));
            if (now()->gt($cutoff)) {
                $priority = 'URGENT';
            }
        }

        return DB::transaction(function () use ($u, $data, $type, $fromId, $to, $lines, $neededBy, $priority, $submit, $source) {
            $r = Requisition::create([
                'number' => Numbering::next(config("groupops.requisition_types.$type.3"), 'requisitions'),
                'type' => $type, 'from_location_id' => $fromId, 'to_location_id' => $to->id, 'requester_id' => $u->id,
                'needed_by' => $neededBy, 'priority' => $priority, 'status' => 'DRAFT', 'source' => $source,
                'notes' => $data['notes'] ?? null, 'catering_event_id' => $data['catering_event_id'] ?? null,
            ]);
            $total = 0;
            foreach ($lines as $l) {
                $item = ! empty($l['item_id']) ? Item::find($l['item_id']) : null;
                if ($item && $item->isSaleItem()) { // menu (sale) items are never requested / bought as stock
                    throw new BizException($item->name.' is a sale (menu) item. Request its raw materials or the central kitchen item instead.');
                }
                $price = (float) ($l['est_price'] ?? $item?->last_price ?? 0);
                $r->lines()->create(['item_id' => $item?->id, 'description' => $l['description'] ?? null, 'qty_requested' => (float) $l['qty'], 'est_price' => $price, 'note' => $l['note'] ?? null]);
                $total += $price * (float) $l['qty'];
            }
            $r->update(['estimated_amount' => round($total, 2)]);
            if (! empty($data['parent_ids'])) {
                $r->parents()->syncWithoutDetaching($data['parent_ids']);
            }
            static::history($r, null, 'DRAFT', $u, $source !== 'MANUAL' ? 'Created by system ('.strtolower($source).')' : null);
            if ($submit) {
                static::submit($r->fresh(), $u);
            }

            return $r->fresh();
        });
    }

    public static function submit(Requisition $r, User $u): void
    {
        if ($r->status !== 'DRAFT') {
            throw new BizException('Only drafts can be submitted.');
        }
        static::setStatus($r, 'PENDING_APPROVAL', $u);
        ApprovalService::start($r, $r->requester ?? $u);
    }

    public static function onApproved(Requisition $r, User $by): void
    {
        static::setStatus($r, 'SENT_TO_FULFILLER', $by, 'Approved');
        $perm = $r->type === 'PURCHASE' ? 'purchase.manage' : 'req.fulfil';
        $loc = $r->type === 'PURCHASE' ? null : $r->to_location_id;
        Notifier::send(Notifier::usersWithPerm($perm, $loc), 'New '.$r->typeLabel().' '.$r->number, 'From '.$r->fromLocation?->name.' · needed '.$r->needed_by?->format('d M h:i A').($r->priority === 'URGENT' ? ' · URGENT' : ''), 'request', '/requisitions/'.$r->id);
        Notifier::send($r->requester_id, $r->number.' approved', 'Sent to '.$r->toLocation?->name, 'success', '/requisitions/'.$r->id);
    }

    public static function onRejected(Requisition $r, User $by, ?string $reason): void
    {
        static::setStatus($r, 'REJECTED', $by, $reason, ['reject_reason' => $reason]);
        Notifier::send($r->requester_id, $r->number.' rejected', $reason, 'warning', '/requisitions/'.$r->id);
    }

    public static function isFulfiller(Requisition $r, User $u): bool
    {
        if ($u->is_super_admin) {
            return true;
        }
        if ($r->type === 'PURCHASE') {
            return $u->hasPerm('purchase.manage');
        }

        return $u->hasPerm('req.fulfil') && ($u->location_id === $r->to_location_id || $u->isGroupWide());
    }

    public static function isReceiver(Requisition $r, User $u): bool
    {
        return $u->is_super_admin || ($u->hasPerm('req.receive') && ($u->location_id === $r->from_location_id || $u->isGroupWide())) || $u->id === $r->requester_id;
    }

    /** lines: [line_id => qty_approved] */
    public static function accept(Requisition $r, User $u, array $qtys = [], ?string $note = null): Requisition
    {
        if (! static::isFulfiller($r, $u)) {
            throw new BizException('Only the fulfilling department can accept.');
        }
        if ($r->status !== 'SENT_TO_FULFILLER') {
            throw new BizException('This request is not waiting for acceptance.');
        }
        $partial = false;
        foreach ($r->lines as $line) {
            $q = array_key_exists($line->id, $qtys) ? max(0, (float) $qtys[$line->id]) : $line->qty_requested;
            $q = min($q, $line->qty_requested);
            if ($q < $line->qty_requested) {
                $partial = true;
            }
            $line->update(['qty_approved' => $q]);
        }
        static::setStatus($r, $partial ? 'PARTIALLY_ACCEPTED' : 'ACCEPTED', $u, $note, ['accepted_by' => $u->id, 'accepted_at' => now()]);
        Notifier::send($r->requester_id, $r->number.($partial ? ' partially accepted' : ' accepted'), 'By '.$u->name.' · '.$r->toLocation?->name.($note ? ' · '.$note : ''), 'success', '/requisitions/'.$r->id);
        if ($r->type === 'REPAIR') {
            static::setStatus($r, 'IN_PROGRESS', $u);
        }

        return $r->fresh();
    }

    public static function rejectByFulfiller(Requisition $r, User $u, string $reason): Requisition
    {
        if (! static::isFulfiller($r, $u)) {
            throw new BizException('Only the fulfilling department can reject.');
        }
        if (! in_array($r->status, ['SENT_TO_FULFILLER', 'ACCEPTED', 'PARTIALLY_ACCEPTED'])) {
            throw new BizException('Cannot reject at this stage.');
        }
        static::setStatus($r, 'REJECTED', $u, $reason, ['reject_reason' => $reason]);
        Notifier::send($r->requester_id, $r->number.' rejected by '.$r->toLocation?->name, $reason, 'warning', '/requisitions/'.$r->id);

        return $r->fresh();
    }

    /** Warehouse/CK is short: raise a linked purchase request for the shortage. */
    public static function raiseShortagePurchase(Requisition $r, User $u): Requisition
    {
        if (! static::isFulfiller($r, $u)) {
            throw new BizException('Only the fulfilling department can do this.');
        }
        $lines = [];
        foreach ($r->lines()->with('item')->get() as $line) {
            if (! $line->item_id) {
                continue;
            }
            $short = $line->qty_requested - StockService::qty($r->to_location_id, $line->item_id);
            if ($short > 0.0005) {
                $lines[] = ['item_id' => $line->item_id, 'qty' => round($short, 3), 'note' => 'Shortage for '.$r->number];
            }
        }
        if (! $lines) {
            throw new BizException('There is no shortage — stock is enough.');
        }
        $pr = static::create($u, [
            'type' => 'PURCHASE', 'lines' => $lines, 'needed_by' => $r->needed_by?->copy()->subHours(4) ?? now()->addHours(6),
            'priority' => $r->priority, 'notes' => 'Shortage for '.$r->number, 'parent_ids' => [$r->id],
            'force_from_location_id' => $r->to_location_id,
        ], true, 'AUTO');
        static::history($r, $r->status, $r->status, $u, 'Purchase request '.$pr->number.' raised for shortage');

        return $pr;
    }

    /** lines: [line_id => qty_sent]; moves stock out of the fulfilling location. */
    public static function dispatch(Requisition $r, User $u, array $qtys = [], ?int $driverId = null, ?string $vehicle = null): Requisition
    {
        if (! static::isFulfiller($r, $u)) {
            throw new BizException('Only the fulfilling department can dispatch.');
        }
        if (! in_array($r->status, ['ACCEPTED', 'PARTIALLY_ACCEPTED', 'IN_PROGRESS'])) {
            throw new BizException('Accept the request before dispatching.');
        }
        DB::transaction(function () use ($r, $u, $qtys, $driverId, $vehicle) {
            $any = false;
            foreach ($r->lines as $line) {
                $q = array_key_exists($line->id, $qtys) ? max(0, (float) $qtys[$line->id]) : ($line->qty_approved ?? $line->qty_requested);
                $line->update(['qty_sent' => $q]);
                if ($q > 0 && $line->item_id) {
                    StockService::move($r->to_location_id, $line->item_id, -$q, 'TRANSFER_OUT', $r, null, 'To '.$r->fromLocation?->name, false);
                    $any = true;
                }
            }
            if (! $any) {
                throw new BizException('Nothing to send.');
            }
            $d = Dispatch::create([
                'number' => Numbering::next('DSP', 'dispatches'), 'requisition_id' => $r->id,
                'from_location_id' => $r->to_location_id, 'to_location_id' => $r->from_location_id,
                'driver_id' => $driverId, 'vehicle' => $vehicle, 'status' => $driverId ? 'READY' : 'DELIVERED',
                'delivered_at' => $driverId ? null : now(),
            ]);
            static::setStatus($r, 'DISPATCHED', $u, $driverId ? 'Driver: '.User::find($driverId)?->name : null, ['dispatched_at' => now()]);
            if ($driverId) {
                Notifier::send($driverId, 'New delivery '.$d->number, $r->toLocation?->name.' → '.$r->fromLocation?->name, 'delivery', '/deliveries');
            }
            Notifier::send(array_merge([$r->requester_id], Notifier::usersWithPerm('req.receive', $r->from_location_id)->pluck('id')->all()), $r->number.' dispatched', 'On the way from '.$r->toLocation?->name, 'delivery', '/requisitions/'.$r->id);
        });

        return $r->fresh();
    }

    /** lines: [line_id => qty_received] */
    public static function receive(Requisition $r, User $u, array $qtys = [], ?string $note = null): Requisition
    {
        if (! static::isReceiver($r, $u)) {
            throw new BizException('Only the requesting location can confirm receipt.');
        }
        $okStatus = $r->type === 'PURCHASE' ? ['DELIVERED', 'IN_PROGRESS'] : ['DISPATCHED'];
        if (! in_array($r->status, $okStatus)) {
            throw new BizException('Nothing to receive yet.');
        }
        DB::transaction(function () use ($r, $u, $qtys, $note) {
            $short = [];
            foreach ($r->lines()->with('item')->get() as $line) {
                $sent = $r->type === 'PURCHASE' ? ($line->qty_sent ?? $line->qty_approved ?? $line->qty_requested) : (float) $line->qty_sent;
                $q = array_key_exists($line->id, $qtys) ? max(0, (float) $qtys[$line->id]) : $sent;
                $line->update(['qty_received' => $q]);
                if ($q + 0.0005 < $sent) {
                    $short[] = ($line->item?->name ?? $line->description).': '.round($sent - $q, 3);
                }
                if ($q > 0 && $line->item_id) {
                    if ($r->type === 'PURCHASE') {
                        StockService::move($r->from_location_id, $line->item_id, $q, 'PURCHASE_IN', $r, $line->est_price, 'Goods received');
                    } else {
                        StockService::move($r->from_location_id, $line->item_id, $q, 'TRANSFER_IN', $r, null, 'From '.$r->toLocation?->name);
                    }
                }
            }
            $n = trim(($note ?? '').($short ? ' Shortage: '.implode(', ', $short) : ''));
            static::setStatus($r, 'RECEIVED', $u, $n ?: null, ['received_at' => now(), 'received_by' => $u->id]);
            $r->dispatch?->update(['status' => 'RECEIVED']);
            if ($r->type === 'PURCHASE') {
                PurchaseService::onGoodsReceived($r, $u);
            }
            static::setStatus($r, 'CLOSED', $u);
            Notifier::send(Notifier::usersWithPerm('req.fulfil', $r->to_location_id)->pluck('id')->push($r->accepted_by)->filter()->all(), $r->number.' received', $u->name.' at '.$r->fromLocation?->name.($short ? ' · shortage reported' : ''), $short ? 'warning' : 'success', '/requisitions/'.$r->id);
            if ($short) {
                Notifier::send(Location::find($r->from_location_id)?->manager_id ?? $r->requester_id, 'Shortage on '.$r->number, implode(', ', $short), 'warning', '/requisitions/'.$r->id);
            }
        });

        return $r->fresh();
    }

    public static function complete(Requisition $r, User $u, ?string $note = null): Requisition
    {
        if (! static::isFulfiller($r, $u) || $r->type !== 'REPAIR' || $r->status !== 'IN_PROGRESS') {
            throw new BizException('Cannot complete this request.');
        }
        static::setStatus($r, 'CLOSED', $u, $note ?? 'Repair done');
        Notifier::send($r->requester_id, $r->number.' completed', $note, 'success', '/requisitions/'.$r->id);

        return $r->fresh();
    }

    public static function cancel(Requisition $r, User $u, ?string $reason = null): Requisition
    {
        if ($r->requester_id !== $u->id && ! $u->is_super_admin) {
            throw new BizException('Only the requester can cancel.');
        }
        if (! in_array($r->status, ['DRAFT', 'PENDING_APPROVAL', 'SENT_TO_FULFILLER'])) {
            throw new BizException('Too late to cancel — it is already being fulfilled.');
        }
        \App\Models\ApprovalStep::where('approvable_type', $r->getMorphClass())->where('approvable_id', $r->id)->whereIn('status', ['WAITING', 'PENDING'])->update(['status' => 'SKIPPED']);
        static::setStatus($r, 'CANCELLED', $u, $reason);

        return $r->fresh();
    }

    public static function updateDraftLines(Requisition $r, User $u, array $qtys): Requisition
    {
        if ($r->status !== 'DRAFT') {
            throw new BizException('Locked — only drafts can be edited.');
        }
        if ($r->requester_id !== $u->id && ! ($u->hasPerm('req.standing') && $u->location_id === $r->from_location_id) && ! $u->is_super_admin) {
            throw new BizException('You cannot edit this order.');
        }
        $total = 0;
        foreach ($r->lines as $line) {
            if (array_key_exists($line->id, $qtys)) {
                $line->update(['qty_requested' => max(0, (float) $qtys[$line->id])]);
            }
            $total += $line->qty_requested * $line->est_price;
        }
        $r->update(['estimated_amount' => round($total, 2)]);
        static::history($r, 'DRAFT', 'DRAFT', $u, 'Quantities edited');

        return $r->fresh();
    }

    // ---------------- Visibility & chain ----------------

    public static function visibleTo(User $u): Builder
    {
        $q = Requisition::query();
        if ($u->isGroupWide()) {
            return $q;
        }

        return $q->where(function ($w) use ($u) {
            $w->where('requester_id', $u->id)
                ->orWhereHas('purchaseAssignment', fn ($p) => $p->where('buyer_id', $u->id));
            if ($u->hasAnyPerm(['req.fulfil', 'req.receive', 'approvals.act']) || in_array($u->role?->scope, ['LOCATION', 'TEAM'])) {
                $w->orWhere('from_location_id', $u->location_id)->orWhere('to_location_id', $u->location_id);
            }
            if ($u->hasPerm('purchase.manage')) {
                $w->orWhere('type', 'PURCHASE');
            }
        });
    }

    /** A human sentence describing where a request is stuck (follows linked children). */
    public static function waitingOn(Requisition $r, int $depth = 0): string
    {
        $r->loadMissing('children', 'toLocation', 'purchaseAssignment.buyer', 'dispatch.driver');
        $open = $r->children->first(fn ($c) => ! in_array($c->status, ['CLOSED', 'CANCELLED', 'REJECTED']));
        if ($open && $depth < 4) {
            return $r->toLocation?->name.' → '.static::waitingOn($open, $depth + 1);
        }
        $pa = $r->purchaseAssignment;

        return match ($r->status) {
            'DRAFT' => 'Draft (not submitted)',
            'PENDING_APPROVAL' => 'Waiting for approval',
            'SENT_TO_FULFILLER' => 'Waiting for '.$r->toLocation?->name.' to accept',
            'ACCEPTED', 'PARTIALLY_ACCEPTED' => $r->type === 'PURCHASE' ? 'Approved, buyer not yet assigned' : $r->toLocation?->name.' preparing',
            'IN_PROGRESS' => $pa ? 'Buying '.($r->lines->first()?->item?->name ?? '').' ('.$pa->buyer?->name.', due '.$pa->deadline_at->format('d M h:i A').')' : 'In progress at '.$r->toLocation?->name,
            'DELIVERED' => 'Delivered by buyer, waiting goods received',
            'DISPATCHED' => 'On the way'.($r->dispatch?->driver ? ' ('.$r->dispatch->driver->name.')' : ''),
            default => ucfirst(strtolower(str_replace('_', ' ', $r->status))),
        };
    }

    // ---------------- Standing (fixed) orders ----------------

    /** Create tomorrow's DRAFT food orders from par lists (idempotent). */
    public static function generateStanding(?Carbon $for = null): int
    {
        $for = ($for ?? today()->addDay())->copy()->startOfDay();
        $n = 0;
        $byLoc = StandingOrder::where('active', true)->where('weekday', $for->dayOfWeek)->where('qty', '>', 0)->get()->groupBy('location_id');
        foreach ($byLoc as $locId => $rows) {
            if (! Features::enabled('req.standing_order', $locId)) {
                continue;
            }
            $exists = Requisition::where('source', 'STANDING')->where('from_location_id', $locId)->whereDate('needed_by', $for)->exists();
            if ($exists) {
                continue;
            }
            $loc = Location::find($locId);
            $requester = $loc?->manager ?? User::where('location_id', $locId)->first();
            if (! $requester) {
                continue;
            }
            static::create($requester, [
                'type' => 'FOOD_ORDER', 'force_from_location_id' => $locId, 'needed_by' => $for->copy()->setTime(11, 0),
                'lines' => $rows->map(fn ($s) => ['item_id' => $s->item_id, 'qty' => $s->qty])->all(),
                'notes' => 'Fixed order for '.$for->format('l d M'),
            ], false, 'STANDING');
            Notifier::send($requester, 'Fixed order ready to review', 'For '.$for->format('D d M').' · edit until '.Features::setting('orders.cutoff_time', '20:00'), 'request');
            $n++;
        }

        return $n;
    }

    /** At cut-off, lock & submit standing drafts for tomorrow. */
    public static function submitDueStanding(bool $force = false): int
    {
        $cutoff = Carbon::parse(today()->toDateString().' '.Features::setting('orders.cutoff_time', '20:00'));
        if (! $force && now()->lt($cutoff)) {
            return 0;
        }
        $n = 0;
        foreach (Requisition::where('source', 'STANDING')->where('status', 'DRAFT')->whereDate('needed_by', '<=', today()->addDay())->get() as $r) {
            static::submit($r, $r->requester);
            $n++;
        }

        return $n;
    }
}
