<?php

namespace App\Services;

use App\Exceptions\BizException;
use App\Models\Roster;
use App\Models\Task;
use App\Models\TaskTemplate;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Carbon;

class TaskService
{
    public const PRIORITIES = ['LOW', 'NORMAL', 'HIGH', 'URGENT'];

    /**
     * Assign a task to people and/or teams.
     * Team mode LEAD = one task for the team lead (who shares it out); EACH = one task per member.
     * Options: remind_before_minutes, remind_every_minutes (while overdue), notify_on_start, notify_on_done.
     */
    public static function create(User $by, array $data, array $assigneeIds, array $teamIds = [], string $teamMode = 'LEAD'): array
    {
        if (! $by->hasPerm('tasks.assign')) {
            throw new BizException('You are not allowed to assign tasks.');
        }
        $allowed = $by->assignableIds();
        $targets = [];
        foreach (array_unique(array_map('intval', $assigneeIds)) as $uid) {
            if (! in_array($uid, $allowed)) {
                throw new BizException('You can only assign tasks to people in your team.');
            }
            $targets[$uid] = null;
        }
        if ($teamIds && ! $by->featureOn('teams.org')) {
            throw new BizException('Teams are not switched on.');
        }
        foreach (Team::whereIn('id', $teamIds)->get() as $team) {
            if (! $by->isGroupWide() && $team->lead_id !== $by->id && ! in_array($team->lead_id, $allowed)) {
                throw new BizException('Team “'.$team->name.'” is not under you.');
            }
            $ids = $teamMode === 'EACH' ? $team->memberIds() : array_filter([$team->lead_id]);
            if (! $ids) {
                throw new BizException('Team “'.$team->name.'” has no '.($teamMode === 'EACH' ? 'members' : 'lead').'.');
            }
            foreach ($ids as $uid) {
                $targets[(int) $uid] ??= $team->id;
            }
        }
        unset($targets[$by->id]);
        if (! $targets) {
            throw new BizException('Choose at least one person or team.');
        }
        $priority = in_array($data['priority'] ?? 'NORMAL', self::PRIORITIES) ? ($data['priority'] ?? 'NORMAL') : 'NORMAL';
        $due = \App\Support\Dt::parse($data['due_at']);
        $rem = $by->featureOn('tasks.reminders');
        $num = fn ($v) => ($v === null || $v === '' || (int) $v <= 0) ? null : min(1440, (int) $v);
        $bool = fn ($v, bool $d) => ($v === null || $v === '') ? $d : filter_var($v, FILTER_VALIDATE_BOOLEAN);
        $tasks = [];
        foreach ($targets as $uid => $teamId) {
            $assignee = User::findOrFail($uid);
            $tasks[] = $t = Task::create([
                'title' => $data['title'], 'title_bn' => $data['title_bn'] ?? null,
                'description' => $data['description'] ?? null, 'assigned_by' => $by->id, 'assignee_id' => $assignee->id,
                'location_id' => $assignee->location_id, 'team_id' => $teamId, 'priority' => $priority, 'due_at' => $due,
                'proof_required' => (bool) ($data['proof_required'] ?? false) && $by->featureOn('tasks.proof_photo'),
                'remind_before_minutes' => $rem ? $num($data['remind_before_minutes'] ?? null) : null,
                'remind_every_minutes' => $rem ? $num($data['remind_every_minutes'] ?? null) : null,
                'notify_on_start' => $rem && $bool($data['notify_on_start'] ?? null, false),
                'notify_on_done' => $rem && $bool($data['notify_on_done'] ?? null, true),
            ]);
            $tag = in_array($priority, ['HIGH', 'URGENT']) ? '['.($priority === 'URGENT' ? 'TOP PRIORITY' : 'HIGH').'] ' : '';
            Notifier::send($assignee, $tag.'New task: '.$data['title'], 'Due '.$due->format('d M, h:i A').' · from '.$by->name.($teamId ? ' · team '.$t->team?->name : ''), $priority === 'URGENT' ? 'warning' : 'task', '/tasks');
        }

        return $tasks;
    }

    /** Assigner (or a manager above the assignee) nudges the assignee right now. */
    public static function remindNow(Task $t, User $by): Task
    {
        $can = $by->is_super_admin || $t->assigned_by === $by->id || in_array($t->assignee_id, $by->subordinateIds()) || ($by->hasPerm('tasks.view_team') && in_array($t->assignee_id, $by->scopedUserIds()));
        if (! $can) {
            throw new BizException('Only the person who assigned it or a manager can send a reminder.');
        }
        if (! in_array($t->status, ['ASSIGNED', 'STARTED'])) {
            throw new BizException('This task is not open.');
        }
        if ($t->last_reminded_at && $t->last_reminded_at->gt(now()->subMinutes(5))) {
            throw new BizException('A reminder was already sent at '.$t->last_reminded_at->format('h:i A').'. Try again in a few minutes.');
        }
        $late = $t->due_at->isPast() ? ' · '.static::lateText($t) : ' · due '.$t->due_at->format('h:i A');
        Notifier::send($t->assignee_id, 'Reminder from '.$by->name.': '.$t->title, 'Please finish it'.$late, 'warning', '/tasks');
        $t->update(['last_reminded_at' => now(), 'reminders_sent' => $t->reminders_sent + 1]);

        return $t;
    }

    protected static function lateText(Task $t): string
    {
        $m = (int) $t->due_at->diffInMinutes(now());

        return 'late by '.($m >= 60 ? intdiv($m, 60).'h '.($m % 60).'m' : $m.' min');
    }

    /**
     * Automatic reminders (every minute from ops:tick):
     *  - once, N minutes before the due time (remind_before_minutes)
     *  - repeatedly while overdue: every remind_every_minutes, or by priority (HIGH / URGENT settings), up to the max.
     */
    public static function sendReminders(): int
    {
        $n = 0;
        $max = (int) \App\Support\Features::setting('tasks.max_reminders', 6);
        $open = Task::whereIn('status', ['ASSIGNED', 'STARTED']);

        foreach ((clone $open)->whereNotNull('remind_before_minutes')->whereNull('pre_reminded_at')->where('due_at', '>', now())->where('due_at', '<=', now()->addDay())->get() as $t) {
            if ($t->due_at->lte(now()->addMinutes($t->remind_before_minutes)) && \App\Support\Features::enabled('tasks.reminders', $t->location_id, true)) {
                Notifier::send($t->assignee_id, 'Reminder: '.$t->title, 'Due at '.$t->due_at->format('h:i A').' (in '.max(1, (int) now()->diffInMinutes($t->due_at)).' min)', 'task', '/tasks');
                $t->update(['pre_reminded_at' => now()]);
                $n++;
            }
        }

        $overdue = (clone $open)->where('due_at', '<', now())->where('reminders_sent', '<', $max)->where('due_at', '>', now()->subDays(2))
            ->where(fn ($w) => $w->whereNotNull('remind_every_minutes')->orWhereIn('priority', ['HIGH', 'URGENT']))->with('assigner')->get();
        foreach ($overdue as $t) {
            $every = $t->repeatEvery();
            if (! $every || ! \App\Support\Features::enabled('tasks.reminders', $t->location_id, true)) {
                continue;
            }
            $last = $t->last_reminded_at && $t->last_reminded_at->gt($t->due_at) ? $t->last_reminded_at : null;
            if ($last ? $last->gt(now()->subMinutes($every)) : $t->due_at->gt(now()->subMinutes(1))) {
                continue;
            }
            Notifier::send($t->assignee_id, ($t->priority === 'URGENT' ? 'TOP PRIORITY overdue: ' : 'Overdue: ').$t->title, ucfirst(static::lateText($t)).($t->assigner ? ' · assigned by '.$t->assigner->name : ''), 'warning', '/tasks');
            $t->update(['last_reminded_at' => now(), 'reminders_sent' => $t->reminders_sent + 1]);
            $n++;
        }

        return $n;
    }
    public static function systemTask(User $assignee, string $title, $dueAt, $linked = null, ?User $by = null): Task
    {
        $t = Task::create([
            'title' => $title, 'assigned_by' => $by?->id, 'assignee_id' => $assignee->id, 'location_id' => $assignee->location_id,
            'due_at' => Carbon::parse($dueAt), 'linked_type' => $linked ? class_basename($linked) : null, 'linked_id' => $linked?->getKey(), 'notify_on_done' => false,
        ]);
        Notifier::send($assignee, 'New task: '.$title, 'Due '.Carbon::parse($dueAt)->format('d M, h:i A'), 'task', '/tasks');

        return $t;
    }

    protected static function ensureAssignee(Task $t, User $u): void
    {
        if ($t->assignee_id !== $u->id && ! $u->is_super_admin) {
            throw new BizException('This task is not assigned to you.');
        }
    }

    public static function start(Task $t, User $u): Task
    {
        static::ensureAssignee($t, $u);
        if (! in_array($t->status, ['ASSIGNED'])) {
            throw new BizException('Task already started.');
        }
        $t->update(['status' => 'STARTED', 'started_at' => now()]);
        if ($t->notify_on_start && $t->assigned_by && $t->assigned_by !== $u->id) {
            Notifier::send($t->assigned_by, 'Task started: '.$t->title, $u->name.' started at '.now()->format('h:i A'), 'task', '/tasks');
        }

        return $t;
    }

    /** Worker marks done (with proof when required). Tasks assigned by someone go to SUBMITTED for approval only if proof required. */
    public static function complete(Task $t, User $u, ?string $note = null, ?string $photoPath = null): Task
    {
        static::ensureAssignee($t, $u);
        if (in_array($t->status, ['DONE', 'CANCELLED', 'SUBMITTED'])) {
            throw new BizException('Task is already '.strtolower($t->status).'.');
        }
        if ($t->proof_required && ! $photoPath && ! $t->proof_photo) {
            throw new BizException('This task needs a photo as proof.');
        }
        $fields = ['proof_note' => $note ?? $t->proof_note, 'started_at' => $t->started_at ?? now()];
        if ($photoPath) {
            $fields['proof_photo'] = $photoPath;
        }
        if ($t->proof_required && $t->assigned_by) {
            $fields += ['status' => 'SUBMITTED', 'submitted_at' => now()];
            $t->update($fields);
            Notifier::send($t->assigned_by, 'Task submitted for review', $t->title.' · '.$u->name, 'task', '/tasks');
        } else {
            $fields += ['status' => 'DONE', 'submitted_at' => now(), 'completed_at' => now()];
            $t->update($fields);
            if ($t->notify_on_done && $t->assigned_by && $t->assigned_by !== $u->id) {
                $onTime = $t->completed_at->lte($t->due_at);
                Notifier::send($t->assigned_by, 'Task finished: '.$t->title, $u->name.' finished at '.now()->format('h:i A').' · '.($onTime ? 'on time' : static::lateText($t)), $onTime ? 'success' : 'warning', '/tasks');
            }
        }

        return $t;
    }

    public static function review(Task $t, User $reviewer, bool $approve, ?string $note = null): Task
    {
        $canReview = $reviewer->is_super_admin || $t->assigned_by === $reviewer->id || in_array($t->assignee_id, $reviewer->subordinateIds());
        if (! $canReview) {
            throw new BizException('You cannot review this task.');
        }
        if ($t->status !== 'SUBMITTED') {
            throw new BizException('Task is not waiting for review.');
        }
        if ($approve) {
            // completion time is when the worker submitted it
            $t->update(['status' => 'DONE', 'completed_at' => $t->submitted_at ?? now()]);
            Notifier::send($t->assignee_id, 'Task approved ✓', $t->title, 'success', '/tasks');
        } else {
            $t->update(['status' => 'STARTED', 'rework_count' => $t->rework_count + 1, 'submitted_at' => null, 'proof_note' => $note]);
            Notifier::send($t->assignee_id, 'Task sent back for rework', $t->title.($note ? ' · '.$note : ''), 'warning', '/tasks');
        }

        return $t;
    }

    /** Create today's recurring checklist tasks (idempotent). */
    public static function generateRecurring(?Carbon $date = null): int
    {
        $date = ($date ?? today())->copy()->startOfDay();
        $count = 0;
        foreach (TaskTemplate::where('active', true)->with('location')->get() as $tpl) {
            if ($tpl->last_generated_for && $tpl->last_generated_for->gte($date)) {
                continue;
            }
            if (! \App\Support\Features::enabled('tasks.recurring', $tpl->location_id)) {
                continue;
            }
            if ($tpl->recurrence === 'WEEKLY' && ! in_array((string) $date->dayOfWeek, explode(',', (string) $tpl->weekdays))) {
                $tpl->update(['last_generated_for' => $date]);
                continue;
            }
            $due = $date->copy()->setTimeFromTimeString($tpl->due_time);
            if ($tpl->assignee_id) {
                $assignees = [$tpl->assignee_id];
            } else {
                // every on-shift member of the team lead's team (incl. the lead) at this location
                $team = $tpl->team_lead_id ? array_merge([$tpl->team_lead_id], User::find($tpl->team_lead_id)?->subordinateIds() ?? []) : User::where('location_id', $tpl->location_id)->pluck('id')->all();
                $assignees = Roster::whereDate('date', $date)->where('is_off', false)->whereIn('user_id', $team)->pluck('user_id')->all();
                // claimed by one person: give it to the lead only if on shift, else first on shift
                $assignees = $tpl->team_lead_id && in_array($tpl->team_lead_id, $assignees) ? [$tpl->team_lead_id] : array_slice($assignees, 0, 1);
            }
            foreach ($assignees as $uid) {
                Task::create([
                    'title' => $tpl->title, 'title_bn' => $tpl->title_bn, 'description' => $tpl->description,
                    'assigned_by' => $tpl->created_by, 'assignee_id' => $uid, 'location_id' => $tpl->location_id,
                    'template_id' => $tpl->id, 'due_at' => $due, 'proof_required' => $tpl->proof_required, 'notify_on_done' => false,
                ]);
                $count++;
            }
            $tpl->update(['last_generated_for' => $date]);
        }

        return $count;
    }

    /** Escalate overdue tasks once: notify assigner and the assigner's manager. */
    public static function escalateOverdue(): int
    {
        $n = 0;
        $tasks = Task::whereIn('status', ['ASSIGNED', 'STARTED'])->where('escalated', false)->where('due_at', '<', now()->subMinutes(15))->with('assignee', 'assigner')->get();
        foreach ($tasks as $t) {
            if (! \App\Support\Features::enabled('tasks.escalation', $t->location_id)) {
                continue;
            }
            $to = array_filter([$t->assigned_by, $t->assigner?->manager_id, $t->assignee?->manager_id]);
            Notifier::send($to, 'Overdue task', $t->title.' · '.$t->assignee?->name.' · due '.$t->due_at->format('h:i A'), 'warning', '/tasks');
            $t->update(['escalated' => true]);
            $n++;
        }

        return $n;
    }
}
