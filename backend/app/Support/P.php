<?php

namespace App\Support;

use App\Models\ApprovalStep;
use App\Models\PurchaseAssignment;
use App\Models\Requisition;
use App\Models\Task;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\PurchaseService;
use App\Services\RequisitionService;

/** JSON presenters for the mobile API. */
class P
{
    public static function photo(?string $path): ?string
    {
        return $path ? url('storage/'.$path) : null;
    }

    public static function userBrief(?User $u): ?array
    {
        if (! $u) {
            return null;
        }

        return ['id' => $u->id, 'code' => $u->employee_code, 'name' => $u->name, 'name_bn' => $u->name_bn, 'position' => $u->position, 'location' => $u->location?->name, 'phone' => $u->phone];
    }

    public static function me(User $u): array
    {
        $u->loadMissing('role', 'location', 'manager', 'department');

        return [
            'id' => $u->id, 'employee_code' => $u->employee_code, 'name' => $u->name, 'name_bn' => $u->name_bn,
            'phone' => $u->phone, 'position' => $u->position, 'language' => $u->language,
            'role' => $u->role?->name, 'role_key' => $u->role?->key, 'tier' => $u->tier(),
            'is_super_admin' => $u->is_super_admin, 'is_company_admin' => $u->is_company_admin, 'group_wide' => $u->isGroupWide(),
            'location' => $u->location ? ['id' => $u->location->id, 'name' => $u->location->name, 'type' => $u->location->type] : null,
            'department' => $u->department?->name, 'manager' => $u->manager?->name,
            'permissions' => $u->effectivePermissions(), 'features' => $u->enabledFeatures(),
            'has_team' => count($u->subordinateIds()) > 0 || $u->isGroupWide(),
            'company' => Features::setting('company.name'),
        ];
    }

    public static function task(Task $t): array
    {
        return [
            'id' => $t->id, 'title' => $t->title, 'title_bn' => $t->title_bn, 'description' => $t->description,
            'status' => $t->status, 'priority' => $t->priority, 'due_at' => $t->due_at?->toIso8601String(),
            'due_label' => $t->due_at?->format('d M, h:i A'), 'overdue' => $t->isOverdue(),
            'proof_required' => $t->proof_required, 'proof_note' => $t->proof_note, 'proof_photo' => static::photo($t->proof_photo),
            'rework_count' => $t->rework_count, 'recurring' => (bool) $t->template_id,
            'completed_at' => $t->completed_at?->format('d M, h:i A'),
            'on_time' => $t->completed_at ? $t->completed_at->lte($t->due_at) : null,
            'assignee' => static::userBrief($t->assignee), 'assigned_by' => $t->assigner?->name, 'assigned_by_id' => $t->assigned_by,
            'team' => $t->team?->name,
            'remind_before' => $t->remind_before_minutes, 'remind_every' => $t->repeatEvery(), 'reminders_sent' => $t->reminders_sent,
            'last_reminded' => $t->last_reminded_at?->format('h:i A'),
            'notify_on_done' => (bool) $t->notify_on_done, 'notify_on_start' => (bool) $t->notify_on_start,
            'delivery' => static::taskDelivery($t),
            'can_remind' => ($me = auth()->user()) ? in_array($t->status, ['ASSIGNED', 'STARTED']) && $t->assignee_id !== $me->id && ($t->assigned_by === $me->id || $me->is_super_admin || in_array($t->assignee_id, $me->subordinateIdsCached())) : false,
        ];
    }

    /** Customer, address, items and cash to collect for a rider's delivery task. */
    public static function taskDelivery(Task $t): ?array
    {
        $o = $t->deliveryOrder();
        if (! $o) {
            return null;
        }
        $o->loadMissing('lines.item', 'location');

        return [
            'order_id' => $o->id, 'number' => $o->number, 'status' => $o->delivery_status, 'kitchen_ready' => $o->delivery_status !== 'COOKING',
            'customer_name' => $o->customer_name, 'phone' => $o->customer_phone, 'address' => $o->delivery_address, 'area' => $o->delivery_area,
            'note' => $o->delivery_note, 'maps' => $o->mapsUrl(), 'branch' => $o->location?->name, 'branch_phone' => $o->location?->phone,
            'items' => $o->lines->map(fn ($l) => ['name' => $l->item->name, 'name_bn' => $l->item->name_bn, 'qty' => (float) $l->qty])->values(),
            'total' => (float) $o->total, 'collect' => $o->toCollect(), 'paid' => $o->status === 'PAID',
            'cash_collected' => $o->cash_collected, 'delivered_at' => $o->delivered_at?->format('h:i A'),
        ];
    }

    public static function requisitionBrief(Requisition $r): array
    {
        return [
            'id' => $r->id, 'number' => $r->number, 'type' => $r->type, 'type_label' => $r->typeLabel(), 'status' => $r->status,
            'priority' => $r->priority, 'source' => $r->source, 'from' => $r->fromLocation?->name, 'to' => $r->toLocation?->name,
            'needed_by' => $r->needed_by?->format('d M, h:i A'), 'amount' => $r->estimated_amount,
            'requester' => $r->requester?->name, 'created' => $r->created_at->format('d M, h:i A'),
            'items' => $r->lines->map(fn ($l) => ($l->item?->displayName() ?? $l->description).' '.rtrim(rtrim(number_format($l->qty_requested, 3, '.', ''), '0'), '.').($l->item ? ' '.$l->item->unit : ''))->join(', '),
        ];
    }

    public static function requisition(Requisition $r, User $viewer): array
    {
        $r->load('lines.item', 'histories.user', 'children', 'parents', 'purchaseAssignment.buyer', 'dispatch.driver', 'fromLocation', 'toLocation', 'requester');
        $isF = RequisitionService::isFulfiller($r, $viewer);
        $isR = RequisitionService::isReceiver($r, $viewer);
        $actions = [];
        if ($r->status === 'DRAFT' && ($r->requester_id === $viewer->id || $viewer->hasPerm('req.standing'))) {
            $actions = ['update-draft', 'submit', 'cancel'];
        }
        if (in_array($r->status, ['PENDING_APPROVAL', 'SENT_TO_FULFILLER']) && $r->requester_id === $viewer->id) {
            $actions[] = 'cancel';
        }
        if ($isF && $r->status === 'SENT_TO_FULFILLER') {
            $actions = array_merge($actions, $r->type === 'PURCHASE' ? ['assign', 'reject'] : (in_array($r->type, ['RAW_MATERIAL', 'SUPPLIES', 'EQUIPMENT']) ? ['accept', 'reject', 'shortage'] : ['accept', 'reject']));
        }
        if ($isF && in_array($r->status, ['ACCEPTED', 'PARTIALLY_ACCEPTED']) && $r->type !== 'PURCHASE') {
            $actions = array_merge($actions, in_array($r->type, ['RAW_MATERIAL', 'SUPPLIES', 'EQUIPMENT']) ? ['dispatch', 'shortage', 'reject'] : ['dispatch', 'reject']);
        }
        if ($isF && $r->status === 'IN_PROGRESS' && $r->type === 'REPAIR') {
            $actions[] = 'complete';
        }
        if ($isR && ($r->status === 'DISPATCHED' || ($r->type === 'PURCHASE' && $r->status === 'DELIVERED'))) {
            $actions[] = 'receive';
        }
        $steps = ApprovalService::stepsFor($r);
        $pa = $r->purchaseAssignment;

        return static::requisitionBrief($r) + [
            'notes' => $r->notes, 'reject_reason' => $r->reject_reason,
            'waiting_on' => in_array($r->status, ['CLOSED', 'CANCELLED', 'REJECTED']) ? null : RequisitionService::waitingOn($r),
            'lines' => $r->lines->map(fn ($l) => [
                'id' => $l->id, 'item_id' => $l->item_id, 'name' => $l->item?->displayName() ?? $l->description, 'unit' => $l->item?->unit,
                'qty_requested' => $l->qty_requested, 'qty_approved' => $l->qty_approved, 'qty_sent' => $l->qty_sent, 'qty_received' => $l->qty_received,
                'est_price' => $l->est_price, 'stock_at_fulfiller' => $l->item_id ? \App\Services\StockService::qty($r->to_location_id, $l->item_id) : null, 'note' => $l->note,
            ])->values(),
            'history' => $r->histories->map(fn ($h) => ['status' => $h->to_status, 'by' => $h->user?->name, 'note' => $h->note, 'at' => $h->created_at->format('d M, h:i A')])->values(),
            'approvals' => $steps->map(fn (ApprovalStep $s) => ['level' => $s->level, 'status' => $s->status, 'approver' => $s->approver_user_id ? \App\Models\User::find($s->approver_user_id)?->name : ucwords(str_replace('_', ' ', (string) $s->approver_value)), 'by' => $s->actor?->name, 'comment' => $s->comment, 'at' => $s->acted_at?->format('d M, h:i A')])->values(),
            'children' => $r->children->map(fn ($c) => ['id' => $c->id, 'number' => $c->number, 'type_label' => $c->typeLabel(), 'status' => $c->status])->values(),
            'parents' => $r->parents->map(fn ($c) => ['id' => $c->id, 'number' => $c->number, 'type_label' => $c->typeLabel(), 'status' => $c->status])->values(),
            'purchase' => $pa ? static::purchase($pa) : null,
            'dispatch' => $r->dispatch ? ['number' => $r->dispatch->number, 'driver' => $r->dispatch->driver?->name, 'vehicle' => $r->dispatch->vehicle, 'status' => $r->dispatch->status] : null,
            'actions' => array_values(array_unique($actions)),
        ];
    }

    public static function purchase(PurchaseAssignment $pa): array
    {
        $pa->loadMissing('requisition.lines.item', 'buyer', 'vendor', 'paymentMethod', 'lines');

        return [
            'id' => $pa->id, 'number' => $pa->number, 'status' => $pa->status, 'funding' => $pa->funding,
            'funding_label' => PurchaseService::FUNDING[$pa->funding][0] ?? $pa->funding,
            'deadline' => $pa->deadline_at->format('d M, h:i A'), 'deadline_iso' => $pa->deadline_at->toIso8601String(),
            'overdue' => in_array($pa->status, ['ASSIGNED', 'ACCEPTED']) && $pa->deadline_at->isPast(),
            'buyer' => $pa->buyer?->name, 'vendor' => $pa->vendor?->name, 'payment_method' => $pa->paymentMethod?->name,
            'amount' => $pa->actual_amount, 'receipt_photo' => static::photo($pa->receipt_photo), 'price_flag' => $pa->price_flag,
            'requisition_id' => $pa->requisition_id, 'requisition_number' => $pa->requisition?->number,
            'deliver_to' => $pa->requisition?->fromLocation?->name,
            'lines' => $pa->requisition?->lines->map(fn ($l) => ['id' => $l->id, 'name' => $l->item?->displayName() ?? $l->description, 'unit' => $l->item?->unit, 'qty' => $l->qty_requested, 'est_price' => $l->est_price, 'last_price' => $l->item?->last_price])->values() ?? [],
        ];
    }
}
