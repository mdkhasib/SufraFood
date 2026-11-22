<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Roster;
use App\Models\Task;
use App\Models\User;
use App\Support\Features;
use Illuminate\Support\Carbon;

class KpiService
{
    /** Month KPI from real task and attendance data. $month = 'Y-m' */
    public static function score(User $u, string $month): array
    {
        $start = Carbon::parse($month.'-01')->startOfDay();
        $end = $start->copy()->endOfMonth();
        $until = $end->isFuture() ? now() : $end;

        $tasks = Task::where('assignee_id', $u->id)->whereBetween('due_at', [$start, $until])->where('status', '!=', 'CANCELLED')->get();
        $total = $tasks->count();
        $done = $tasks->where('status', 'DONE')->count();
        $onTime = $tasks->filter(fn ($t) => $t->status === 'DONE' && $t->completed_at && $t->completed_at->lte($t->due_at))->count();
        $rework = (int) $tasks->sum('rework_count');

        $rostered = Roster::where('user_id', $u->id)->whereBetween('date', [$start->toDateString(), $until->toDateString()])
            ->where('is_off', false)->whereNotNull('shift_template_id')->get()
            ->filter(fn ($r) => ! AttendanceService::onLeave($u->id, $r->date))->count();
        $atts = Attendance::where('user_id', $u->id)->whereBetween('date', [$start->toDateString(), $until->toDateString()])->whereNotNull('check_in_at')->get();
        $present = $atts->count();
        $onTimeAtt = $atts->where('status', '!=', 'LATE')->count();

        $pct = fn ($a, $b) => $b > 0 ? round($a / $b * 100, 1) : null;
        $m = [
            'tasks_total' => $total, 'tasks_done' => $done, 'tasks_on_time' => $onTime, 'rework' => $rework,
            'rostered_days' => $rostered, 'present_days' => $present, 'on_time_days' => $onTimeAtt, 'late_days' => $present - $onTimeAtt,
            'on_time_rate' => $pct($onTime, $total), 'completion_rate' => $pct($done, $total),
            'no_rework_rate' => $total ? round(max(0, 1 - $rework / $total) * 100, 1) : null,
            'attendance_rate' => $pct(min($present, $rostered), $rostered), 'punctuality_rate' => $pct($onTimeAtt, $present),
        ];
        $att = ($m['attendance_rate'] !== null && $m['punctuality_rate'] !== null) ? ($m['attendance_rate'] + $m['punctuality_rate']) / 2 : $m['attendance_rate'];
        $parts = [
            [(float) Features::setting('kpi.w_ontime', 40), $m['on_time_rate']],
            [(float) Features::setting('kpi.w_completion', 20), $m['completion_rate']],
            [(float) Features::setting('kpi.w_rework', 10), $m['no_rework_rate']],
            [(float) Features::setting('kpi.w_attendance', 30), $att],
        ];
        $wSum = 0;
        $sum = 0;
        foreach ($parts as [$w, $v]) {
            if ($v !== null) {
                $wSum += $w;
                $sum += $w * $v;
            }
        }
        $m['attendance_component'] = $att !== null ? round($att, 1) : null;
        $m['score'] = $wSum > 0 ? (int) round($sum / $wSum) : null;
        $m['month'] = $month;

        return $m;
    }

    public static function incentive(?int $score): float
    {
        if ($score === null) {
            return 0;
        }
        if ($score >= (float) Features::setting('payroll.kpi_band_high', 90)) {
            return (float) Features::setting('payroll.kpi_band_high_amount', 1000);
        }
        if ($score >= (float) Features::setting('payroll.kpi_band_mid', 80)) {
            return (float) Features::setting('payroll.kpi_band_mid_amount', 500);
        }

        return 0;
    }

    public static function team(User $manager, string $month, ?int $locationId = null): array
    {
        $ids = $manager->isGroupWide() ? User::where('status', 'ACTIVE')->pluck('id')->all() : array_unique(array_merge($manager->subordinateIds(), $manager->scopedUserIds()));
        $rows = [];
        foreach (User::with('role', 'location')->whereIn('id', $ids)->when($locationId, fn ($q) => $q->where('location_id', $locationId))->where('is_super_admin', false)->orderBy('location_id')->orderBy('name')->get() as $u) {
            $rows[] = ['user' => $u, 'kpi' => static::score($u, $month)];
        }
        usort($rows, fn ($a, $b) => ($b['kpi']['score'] ?? -1) <=> ($a['kpi']['score'] ?? -1));

        return $rows;
    }
}
