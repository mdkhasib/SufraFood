<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BizException;
use App\Http\Controllers\Controller;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Payslip;
use App\Models\SalaryAdvance;
use App\Models\Task;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\KpiService;
use App\Services\OrgService;
use App\Services\PayrollService;
use App\Services\TaskService;
use App\Support\Features;
use App\Support\P;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class PeopleController extends Controller
{
    // ---------- Tasks ----------
    public function tasks(Request $r)
    {
        $u = $r->user();
        $q = Task::with('assignee.location', 'assigner')->where('status', '!=', 'CANCELLED');
        if ($r->input('scope') === 'team') {
            abort_unless($u->hasPerm('tasks.view_team'), 403);
            $ids = $u->isGroupWide() ? null : array_unique(array_merge($u->subordinateIds(), $u->scopedUserIds()));
            $q->when($ids !== null, fn ($w) => $w->whereIn('assignee_id', $ids))->where('assignee_id', '!=', $u->id);
        } else {
            $q->where('assignee_id', $u->id);
        }
        match ($r->input('filter', 'open')) {
            'open' => $q->whereIn('status', ['ASSIGNED', 'STARTED', 'SUBMITTED']),
            'review' => $q->where('status', 'SUBMITTED'),
            'assigned_by_me' => $q->where('assigned_by', $u->id)->whereIn('status', ['ASSIGNED', 'STARTED', 'SUBMITTED']),
            'done' =>$q->where('status', 'DONE')->where('completed_at', '>=', now()->subDays(7)),
            'today' => $q->whereDate('due_at', today()),
            default => null,
        };

        return $q->orderBy('due_at')->limit(150)->get()->map(fn ($t) => P::task($t))->values();
    }

    public function createTask(Request $r)
    {
        $r->validate(['title' => 'required|string|max:200', 'due_at' => 'required|date', 'assignee_ids' => 'array', 'team_ids' => 'array', 'team_mode' => 'nullable|in:LEAD,EACH']);
        $tasks = TaskService::create($r->user(), $r->only('title', 'title_bn', 'description', 'due_at', 'priority', 'proof_required', 'remind_before_minutes', 'remind_every_minutes', 'notify_on_start', 'notify_on_done'), $r->input('assignee_ids', []), $r->input('team_ids', []), $r->input('team_mode', 'LEAD'));

        return ['message' => count($tasks).' task(s) assigned'];
    }

    public function startTask(Request $r, Task $task)
    {
        return P::task(TaskService::start($task, $r->user()));
    }

    public function completeTask(Request $r, Task $task)
    {
        $path = $r->hasFile('photo') ? $r->file('photo')->store('proofs', 'public') : null;

        return P::task(TaskService::complete($task, $r->user(), $r->input('note'), $path));
    }

    public function reviewTask(Request $r, Task $task)
    {
        return P::task(TaskService::review($task, $r->user(), $r->boolean('approve'), $r->input('note')));
    }

    public function remindTask(Request $r, Task $task)
    {
        TaskService::remindNow($task, $r->user());

        return ['message' => 'Reminder sent to '.$task->assignee->name];
    }

    /** Assign-task picker: direct reports + leads (no front-line crowd), the teams under me, and reminder defaults. */
    public function assignOptions(Request $r)
    {
        $u = $r->user();
        $ids = $r->boolean('all') && Features::setting('tasks.assign_list', 'LEADERS') === 'ALL' ? $u->assignableIds() : $u->assignListIds();
        $direct = User::where('manager_id', $u->id)->pluck('id')->all();
        $people = User::with('location', 'role', 'team')->whereIn('id', $ids)->where('status', 'ACTIVE')->orderBy('location_id')->orderBy('name')->get()
            ->map(fn ($x) => P::userBrief($x) + ['role' => $x->role?->name, 'team' => $x->team?->name, 'direct' => in_array($x->id, $direct), 'leads' => $x->reports()->count()])
            ->sortByDesc('direct')->values();

        return [
            'people' => $people,
            'teams' => OrgService::assignableTeams($u)->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'name_bn' => $t->name_bn, 'lead' => $t->lead?->name, 'location' => $t->location?->code, 'members' => $t->members_count, 'color' => $t->color])->values(),
            'reminders' => $u->featureOn('tasks.reminders'),
            'proof' => $u->featureOn('tasks.proof_photo'),
            'defaults' => ['remind_before' => (int) Features::setting('tasks.default_remind_before', 30), 'high_every' => (int) Features::setting('tasks.high_remind_every', 30), 'urgent_every' => (int) Features::setting('tasks.urgent_remind_every', 15)],
        ];
    }

    public function org(Request $r)
    {
        return ['tree' => OrgService::tree($r->user(), $r->filled('location_id') ? (int) $r->location_id : null, $r->filled('root') ? (int) $r->root : null)];
    }

    public function teams(Request $r)
    {
        return OrgService::teams($r->user())->map(fn ($t) => [
            'id' => $t->id, 'name' => $t->name, 'name_bn' => $t->name_bn, 'color' => $t->color, 'location' => $t->location?->name,
            'parent' => $t->parent?->name, 'lead' => P::userBrief($t->lead),
            'members' => $t->members->where('id', '!=', $t->lead_id)->map(fn ($m) => ['id' => $m->id, 'name' => $m->name, 'code' => $m->employee_code, 'position' => $m->position])->values(),
        ])->values();
    }

    /** People this user may assign tasks to / grant proxy for. */
    public function team(Request $r)
    {
        $u = $r->user();
        $ids = $u->isGroupWide() ? User::pluck('id')->all() : array_unique(array_merge($u->subordinateIds(), $u->scopedUserIds()));

        return User::with('location', 'role')->whereIn('id', $ids)->where('id', '!=', $u->id)->where('status', 'ACTIVE')->where('is_super_admin', false)
            ->orderBy('location_id')->orderBy('name')->get()->map(fn ($x) => P::userBrief($x) + ['role' => $x->role?->name]);
    }

    // ---------- Leave ----------
    public function leave(Request $r)
    {
        $u = $r->user();
        $year = now()->year;

        return [
            'types' => LeaveType::all()->map(function ($t) use ($u, $year) {
                $used = (float) LeaveRequest::where('user_id', $u->id)->where('leave_type_id', $t->id)->where('status', 'APPROVED')->whereYear('from_date', $year)->sum('days');

                return ['id' => $t->id, 'name' => $t->name, 'name_bn' => $t->name_bn, 'days_per_year' => $t->days_per_year, 'used' => $used, 'left' => max(0, $t->days_per_year - $used)];
            }),
            'requests' => LeaveRequest::with('leaveType')->where('user_id', $u->id)->latest('id')->limit(30)->get()->map(fn ($l) => ['id' => $l->id, 'type' => $l->leaveType->name, 'from' => $l->from_date->format('d M'), 'to' => $l->to_date->format('d M'), 'days' => $l->days, 'status' => $l->status, 'reason' => $l->reason]),
        ];
    }

    public function requestLeave(Request $r)
    {
        abort_unless($r->user()->hasPerm('leave.request'), 403, 'Leave requests are not enabled.');
        $r->validate(['leave_type_id' => 'required|exists:leave_types,id', 'from_date' => 'required|date', 'to_date' => 'required|date|after_or_equal:from_date']);
        $days = Carbon::parse($r->from_date)->diffInDays(Carbon::parse($r->to_date)) + 1;
        $type = LeaveType::find($r->leave_type_id);
        $used = (float) LeaveRequest::where('user_id', $r->user()->id)->where('leave_type_id', $type->id)->whereIn('status', ['APPROVED', 'PENDING'])->whereYear('from_date', now()->year)->sum('days');
        if ($type->days_per_year > 0 && $used + $days > $type->days_per_year) {
            throw new BizException('Not enough '.$type->name.' balance ('.max(0, $type->days_per_year - $used).' days left).');
        }
        $l = LeaveRequest::create(['user_id' => $r->user()->id, 'leave_type_id' => $type->id, 'from_date' => $r->from_date, 'to_date' => $r->to_date, 'days' => $days, 'reason' => $r->reason]);
        ApprovalService::start($l, $r->user());

        return ['message' => 'Leave request sent'];
    }

    // ---------- KPI / payslips / advance ----------
    public function kpi(Request $r)
    {
        abort_unless($r->user()->hasPerm('kpi.view_own'), 403, 'KPI is not enabled.');

        return KpiService::score($r->user(), $r->input('month', now()->format('Y-m')));
    }

    public function teamKpi(Request $r)
    {
        abort_unless($r->user()->hasPerm('kpi.view_team'), 403);

        return collect(KpiService::team($r->user(), $r->input('month', now()->format('Y-m'))))->map(fn ($x) => ['user' => P::userBrief($x['user']), 'score' => $x['kpi']['score'], 'on_time_rate' => $x['kpi']['on_time_rate'], 'attendance_rate' => $x['kpi']['attendance_rate'], 'late_days' => $x['kpi']['late_days']])->values();
    }

    public function payslips(Request $r)
    {
        abort_unless($r->user()->hasPerm('payroll.view_own'), 403, 'Payslips are not enabled.');

        return Payslip::with('run')->where('user_id', $r->user()->id)->whereNotNull('paid_at')->latest('id')->get()->map(fn ($p) => $p->toArray() + ['month' => $p->run->month, 'month_label' => Carbon::parse($p->run->month.'-01')->format('F Y')]);
    }

    public function requestAdvance(Request $r)
    {
        $r->validate(['amount' => 'required|numeric|min:1', 'months' => 'required|integer|min:1|max:12']);
        PayrollService::requestAdvance($r->user(), (float) $r->amount, (int) $r->months, $r->reason);

        return ['message' => 'Salary advance request sent'];
    }

    public function advances(Request $r)
    {
        return SalaryAdvance::where('user_id', $r->user()->id)->latest('id')->get();
    }
}
