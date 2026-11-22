<?php

namespace App\Http\Controllers\Web;

use App\Exceptions\BizException;
use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\Task;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Services\OrgService;
use App\Services\TaskService;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    public function index(Request $r)
    {
        $u = $r->user();
        TaskService::generateRecurring();
        $scope = $r->input('scope', $u->hasPerm('tasks.view_team') ? 'team' : 'mine');
        $q = Task::with('assignee.location', 'assigner', 'team');
        if ($scope === 'team' && $u->hasPerm('tasks.view_team')) {
            if (! $u->isGroupWide()) {
                $q->whereIn('assignee_id', array_unique(array_merge($u->subordinateIds(), $u->scopedUserIds())));
            }
        } else {
            $scope = 'mine';
            $q->where('assignee_id', $u->id);
        }
        $status = $r->input('status', 'open');
        match ($status) {
            'open' => $q->whereIn('status', ['ASSIGNED', 'STARTED', 'SUBMITTED']),
            'review' => $q->where('status', 'SUBMITTED'),
            'overdue' => $q->whereIn('status', ['ASSIGNED', 'STARTED'])->where('due_at', '<', now()),
            'mine_assigned' => $q->where('assigned_by', $u->id)->whereIn('status', ['ASSIGNED', 'STARTED', 'SUBMITTED']),
            'done' => $q->where('status', 'DONE'),
            default => null,
        };
        if ($r->filled('location_id')) {
            $q->where('location_id', $r->location_id);
        }
        $tasks = $q->orderBy($status === 'done' ? 'completed_at' : 'due_at', $status === 'done' ? 'desc' : 'asc')->paginate(50)->withQueryString();
        $team = OrgService::assignPicker($u);
        $teams = OrgService::assignableTeams($u);

        return view('tasks.index', compact('tasks', 'team', 'teams', 'scope', 'status'));
    }

    public function store(Request $r)
    {
        $r->validate(['title' => 'required', 'due_at' => 'required|date', 'assignees' => 'array', 'teams' => 'array']);
        $data = $r->only('title', 'title_bn', 'description', 'due_at', 'priority', 'proof_required', 'remind_before_minutes', 'remind_every_minutes')
            + ['notify_on_start' => $r->boolean('notify_on_start'), 'notify_on_done' => $r->boolean('notify_on_done')];
        $tasks = TaskService::create($r->user(), $data, $r->input('assignees', []), $r->input('teams', []), $r->input('team_mode', 'LEAD'));

        return back()->with('ok', count($tasks).' task(s) assigned');
    }

    public function action(Request $r, Task $task, string $action)
    {
        $u = $r->user();
        match ($action) {
            'start' => TaskService::start($task, $u),
            'done' => TaskService::complete($task, $u, $r->input('note'), $r->hasFile('photo') ? $r->file('photo')->store('proofs', 'public') : null),
            'approve' => TaskService::review($task, $u, true),
            'rework' => TaskService::review($task, $u, false, $r->input('note') ?: 'Please redo'),
            'remind' => TaskService::remindNow($task, $u),
            'cancel' => $task->assigned_by === $u->id || $u->is_super_admin ? $task->update(['status' => 'CANCELLED']) : throw new BizException('Only the assigner can cancel.'),
            default => abort(404),
        };

        return back()->with('ok', $action === 'remind' ? 'Reminder sent to '.$task->assignee->name : 'Task updated');
    }

    public function templates(Request $r)
    {
        $u = $r->user();
        $q = TaskTemplate::with('location', 'assignee', 'teamLead', 'creator');
        if (! $u->isGroupWide()) {
            $q->where('location_id', $u->location_id);
        }
        $people = User::where('status', 'ACTIVE')->where('is_super_admin', false)->when(! $u->isGroupWide(), fn ($w) => $w->where('location_id', $u->location_id))->orderBy('name')->get();

        return view('tasks.templates', ['items' => $q->orderBy('location_id')->orderBy('due_time')->get(), 'people' => $people, 'locations' => $u->isGroupWide() ? Location::pluck('name', 'id') : Location::where('id', $u->location_id)->pluck('name', 'id')]);
    }

    public function saveTemplate(Request $r)
    {
        $r->validate(['title' => 'required', 'location_id' => 'required', 'due_time' => 'required']);
        if (! $r->assignee_id && ! $r->team_lead_id) {
            throw new BizException('Choose a person or a team.');
        }
        TaskTemplate::create($r->only('title', 'title_bn', 'description', 'location_id', 'assignee_id', 'team_lead_id', 'recurrence', 'due_time') + ['weekdays' => implode(',', $r->input('weekdays', [])), 'proof_required' => $r->boolean('proof_required'), 'created_by' => $r->user()->id]);

        return back()->with('ok', 'Checklist saved — it will appear in tasks every day');
    }

    public function toggleTemplate(TaskTemplate $template)
    {
        $template->update(['active' => ! $template->active]);

        return back()->with('ok', 'Updated');
    }
}
