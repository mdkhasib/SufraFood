<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\CateringEvent;
use App\Models\Location;
use App\Models\MoneyAccount;
use App\Models\PosOrder;
use App\Models\PurchaseAssignment;
use App\Models\Requisition;
use App\Models\Roster;
use App\Models\Stock;
use App\Models\Task;
use App\Models\User;
use App\Models\VendorBill;
use App\Support\Features;

class DashboardService
{
    /** Management summary. Each card only when its module is live (for group-wide users). */
    public static function management(User $u): array
    {
        $on = fn ($f) => $u->featureOn($f);
        $locIds = $u->isGroupWide() ? null : [$u->location_id];
        $staff = User::where('status', 'ACTIVE')->where('is_super_admin', false)->when($locIds, fn ($q) => $q->whereIn('location_id', $locIds));
        $staffIds = (clone $staff)->pluck('id');
        $cards = [];

        if ($on('attendance.gps_checkin')) {
            $atts = Attendance::whereDate('date', today())->whereIn('user_id', $staffIds)->whereNotNull('check_in_at')->get();
            $rostered = Roster::whereDate('date', today())->whereIn('user_id', $staffIds)->where('is_off', false)->whereNotNull('shift_template_id')->count();
            $cards['attendance'] = [
                'staff' => $staffIds->count(), 'rostered' => $rostered, 'in' => $atts->count(),
                'late' => $atts->where('status', 'LATE')->count(), 'proxy' => $atts->where('method', 'PROXY')->count(),
                'leave' => \App\Models\LeaveRequest::where('status', 'APPROVED')->whereIn('user_id', $staffIds)->whereDate('from_date', '<=', today())->whereDate('to_date', '>=', today())->count(),
            ];
        }
        $cards['approvals'] = ApprovalService::inbox($u)->count();
        if ($on('tasks.basic')) {
            $overdue = Task::whereIn('status', ['ASSIGNED', 'STARTED'])->where('due_at', '<', now())->whereIn('assignee_id', $staffIds)->with('location')->get();
            $cards['tasks'] = [
                'overdue' => $overdue->count(),
                'by_location' => $overdue->groupBy(fn ($t) => $t->location?->name ?? '-')->map->count()->sortDesc()->all(),
                'done_today' => Task::where('status', 'DONE')->whereDate('completed_at', today())->whereIn('assignee_id', $staffIds)->count(),
            ];
        }
        if ($on('inventory.stock')) {
            $open = Requisition::whereNotIn('status', ['CLOSED', 'CANCELLED', 'REJECTED', 'DRAFT'])->when($locIds, fn ($q) => $q->where(fn ($w) => $w->whereIn('from_location_id', $locIds)->orWhereIn('to_location_id', $locIds)));
            $cards['requests'] = ['open' => (clone $open)->count(), 'delayed' => (clone $open)->where('needed_by', '<', now())->count()];
            $low = 0;
            foreach (Location::when($locIds, fn ($q) => $q->whereIn('id', $locIds))->get() as $l) {
                $low += StockService::lowStock($l->id)->count();
            }
            $cards['low_stock'] = $low;
        }
        if ($on('purchase.core')) {
            $cards['purchase'] = [
                'spent_today' => round((float) PurchaseAssignment::whereDate('bought_at', today())->sum('actual_amount'), 2),
                'spent_month' => round((float) PurchaseAssignment::whereMonth('bought_at', now()->month)->whereYear('bought_at', now()->year)->sum('actual_amount'), 2),
                'active' => PurchaseAssignment::whereIn('status', ['ASSIGNED', 'ACCEPTED', 'BOUGHT', 'DELIVERED'])->count(),
            ];
        }
        if ($on('pos.core')) {
            $sales = fn ($date) => PosOrder::where('status', 'PAID')->whereDate('paid_at', $date)->when($locIds, fn ($q) => $q->whereIn('location_id', $locIds));
            $cards['sales'] = [
                'today' => round((float) $sales(today())->sum('total'), 2),
                'yesterday' => round((float) $sales(today()->subDay())->sum('total'), 2),
                'orders_today' => $sales(today())->count(),
                'by_branch' => $sales(today())->with('location')->get()->groupBy(fn ($o) => $o->location->name)->map(fn ($g) => round($g->sum('total'), 2))->all(),
            ];
        }
        if ($on('acc.core') && $u->isGroupWide()) {
            $bal = ['CASH' => 0, 'BANK' => 0, 'MFS' => 0, 'PETTY' => 0, 'DRAWER' => 0];
            foreach (MoneyAccount::where('active', true)->get() as $ma) {
                $bal[$ma->type] = ($bal[$ma->type] ?? 0) + $ma->balance();
            }
            $cards['money'] = array_map(fn ($v) => round($v, 2), $bal);
            $cards['payables_week'] = round((float) VendorBill::where('status', '!=', 'PAID')->whereDate('due_date', '<=', today()->addDays(7))->get()->sum(fn ($b) => $b->amount - $b->paid_amount), 2);
        }
        if ($on('catering.events')) {
            $cards['catering'] = CateringEvent::whereBetween('event_at', [now()->startOfDay(), now()->addDays(7)->endOfDay()])->whereIn('status', ['CONFIRMED', 'INQUIRY'])->count();
        }

        return $cards;
    }

    /** Mobile/web home for any employee. */
    public static function home(User $u): array
    {
        TaskService::generateRecurring();
        $roster = AttendanceService::rosterFor($u);
        $att = Attendance::with('location')->where('user_id', $u->id)->whereDate('date', today())->first();
        $status = AttendanceService::dayStatus($u, today(), $roster, $att);
        $tasks = Task::where('assignee_id', $u->id)->where(fn ($q) => $q->whereDate('due_at', today())->orWhereIn('status', ['ASSIGNED', 'STARTED', 'SUBMITTED']))
            ->where('status', '!=', 'CANCELLED')->orderBy('due_at')->limit(30)->get();
        $loc = Location::find($roster?->location_id ?? $u->location_id);

        return [
            'shift' => $roster && ! $roster->is_off && $roster->shift ? [
                'name' => $roster->shift->name, 'start' => substr($roster->shift->start_time, 0, 5), 'end' => substr($roster->shift->end_time, 0, 5),
                'start2' => $roster->shift->start_time2 ? substr($roster->shift->start_time2, 0, 5) : null, 'end2' => $roster->shift->end_time2 ? substr($roster->shift->end_time2, 0, 5) : null,
                'label' => $roster->shift->label(),
            ] : null,
            'is_off' => (bool) $roster?->is_off,
            'location' => $loc ? ['id' => $loc->id, 'name' => $loc->name, 'lat' => $loc->lat, 'lng' => $loc->lng, 'radius_m' => $loc->radius_m] : null,
            'attendance' => [
                'status' => $status['status'],
                'check_in' => $att?->check_in_at?->format('h:i A'), 'check_out' => $att?->check_out_at?->format('h:i A'),
                'late_minutes' => $att?->late_minutes, 'method' => $att?->method,
            ],
            'tasks' => $tasks,
            'counts' => [
                'tasks_open' => $tasks->whereIn('status', ['ASSIGNED', 'STARTED'])->count(),
                'tasks_overdue' => $tasks->filter(fn ($t) => $t->isOverdue())->count(),
                'notifications' => \App\Models\AppNotification::where('user_id', $u->id)->whereNull('read_at')->count(),
                'approvals' => $u->hasPerm('approvals.act') || $u->isGroupWide() ? ApprovalService::inbox($u)->count() : 0,
            ],
        ];
    }

    public static function stockValue(?int $locationId = null): float
    {
        return round(Stock::with('item')->when($locationId, fn ($q) => $q->where('location_id', $locationId))->get()->sum(fn ($s) => $s->qty * ($s->item?->last_price ?? 0)), 2);
    }
}
