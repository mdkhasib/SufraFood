<?php

use App\Models\ApprovalStep;
use App\Services\ApprovalService;
use App\Services\AttendanceService;
use App\Services\Notifier;
use App\Services\RequisitionService;
use App\Services\TaskService;
use App\Support\Features;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
| One heartbeat that runs every minute: recurring tasks, overdue escalation,
| no-show alerts, auto check-out, fixed orders, approval SLA escalation.
*/
Artisan::command('ops:tick', function () {
    Features::flush();
    $out = [
        'recurring_tasks' => TaskService::generateRecurring(),
        'escalated_tasks' => TaskService::escalateOverdue(),
        'task_reminders' => TaskService::sendReminders(),
        'no_show_alerts' => AttendanceService::noShowCheck(),
        'auto_checkouts' => AttendanceService::autoClose(),
        'standing_created' => RequisitionService::generateStanding(),
        'standing_submitted' => RequisitionService::submitDueStanding(),
    ];
    $sla = 0;
    foreach (ApprovalStep::where('status', 'PENDING')->whereNotNull('due_at')->where('due_at', '<', now())->get() as $step) {
        $approvers = ApprovalService::approversFor($step);
        $managers = $approvers->pluck('manager_id')->filter()->unique()->all();
        Notifier::send($managers ?: $approvers, 'Approval overdue', ApprovalService::titleOf($step->approvable), 'warning', '/approvals');
        $step->update(['due_at' => null]);
        $sla++;
    }
    $out['approval_escalations'] = $sla;
    $this->line(json_encode($out));
})->purpose('Run GroupOps scheduled checks');

Schedule::command('ops:tick')->everyMinute()->withoutOverlapping();
