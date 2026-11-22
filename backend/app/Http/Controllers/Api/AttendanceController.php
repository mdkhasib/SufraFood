<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BizException;
use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\ProxyGrant;
use App\Models\Roster;
use App\Models\User;
use App\Services\AttendanceService;
use App\Support\P;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    protected function gps(Request $r): array
    {
        $r->validate(['lat' => 'required|numeric', 'lng' => 'required|numeric', 'accuracy' => 'nullable|numeric']);

        return [(float) $r->lat, (float) $r->lng, $r->filled('accuracy') ? (float) $r->accuracy : null, $r->boolean('mocked')];
    }

    protected function present(Attendance $a): array
    {
        return ['id' => $a->id, 'status' => $a->status, 'method' => $a->method, 'check_in' => $a->check_in_at?->format('h:i A'), 'check_out' => $a->check_out_at?->format('h:i A'), 'late_minutes' => $a->late_minutes, 'distance_m' => $a->in_distance_m, 'remote_status' => $a->remote_status, 'worked_minutes' => $a->worked_minutes, 'ot_minutes' => $a->ot_minutes, 'ot_status' => $a->ot_status];
    }

    public function checkIn(Request $r)
    {
        [$lat, $lng, $acc, $mock] = $this->gps($r);
        $a = AttendanceService::checkIn($r->user(), $lat, $lng, $acc, $mock, $r->header('X-Device-Id') ?? $r->input('device_id'), $r->boolean('remote'), $r->input('reason'));

        return ['message' => $a->status === 'LATE' ? "Checked in · late {$a->late_minutes} min" : 'Checked in · on time', 'attendance' => $this->present($a)];
    }

    public function checkOut(Request $r)
    {
        [$lat, $lng, $acc, $mock] = $this->gps($r);
        $a = AttendanceService::checkOut($r->user(), $lat, $lng, $acc, $mock);

        return ['message' => 'Checked out · worked '.intdiv($a->worked_minutes, 60).'h '.($a->worked_minutes % 60).'m', 'attendance' => $this->present($a)];
    }

    public function month(Request $r)
    {
        $user = $r->user();
        if ($r->filled('user_id') && (int) $r->user_id !== $user->id) {
            $target = User::findOrFail($r->user_id);
            abort_unless($user->canManageUser($target) || $user->is_super_admin, 403);
            $user = $target;
        }

        return AttendanceService::monthCalendar($user, $r->input('month', now()->format('Y-m')));
    }

    public function team(Request $r)
    {
        abort_unless($r->user()->hasPerm('attendance.team_view'), 403, 'Team attendance is not available for you.');
        $b = AttendanceService::teamBoard($r->user(), $r->input('date', today()->toDateString()));
        $b['rows'] = array_map(fn ($row) => ['user' => P::userBrief($row['user'])] + array_diff_key($row, ['user' => 1]), $b['rows']);

        return $b;
    }

    public function roster(Request $r)
    {
        return Roster::with('shift', 'location')->where('user_id', $r->user()->id)
            ->whereBetween('date', [today()->subDays(1)->toDateString(), today()->addDays((int) $r->input('days', 14))->toDateString()])
            ->orderBy('date')->get()->map(fn ($x) => ['date' => $x->date->toDateString(), 'day' => $x->date->format('D d M'), 'off' => $x->is_off, 'shift' => $x->shift?->label(), 'location' => $x->location?->name]);
    }

    public function proxyList(Request $r)
    {
        if (! $r->user()->featureOn('attendance.proxy_checkin')) {
            return [];
        }

        return AttendanceService::proxyList($r->user())->map(function ($u) {
            $s = AttendanceService::dayStatus($u, today());

            return P::userBrief($u) + ['status' => $s['status'], 'shift' => $s['shift'], 'check_in' => $s['attendance']?->check_in_at?->format('h:i A'), 'check_out' => $s['attendance']?->check_out_at?->format('h:i A')];
        })->values();
    }

    public function proxyCheckIn(Request $r)
    {
        [$lat, $lng, $acc, $mock] = $this->gps($r);
        $a = AttendanceService::proxyCheckIn($r->user(), User::findOrFail($r->input('user_id')), $lat, $lng, $acc, $mock);

        return ['message' => $a->user->name.' checked in ('.strtolower($a->status).')', 'attendance' => $this->present($a)];
    }

    public function proxyCheckOut(Request $r)
    {
        [$lat, $lng, $acc, $mock] = $this->gps($r);
        $a = AttendanceService::proxyCheckOut($r->user(), User::findOrFail($r->input('user_id')), $lat, $lng, $acc, $mock);

        return ['message' => $a->user->name.' checked out', 'attendance' => $this->present($a)];
    }

    public function grants(Request $r)
    {
        abort_unless($r->user()->hasPerm('attendance.proxy_grant'), 403);

        return ProxyGrant::with('proxy', 'staff')->where('manager_id', $r->user()->id)->where('active', true)->get()->map(fn ($g) => [
            'id' => $g->id, 'proxy' => P::userBrief($g->proxy), 'staff' => $g->staff->map(fn ($s) => P::userBrief($s))->values(),
            'valid_from' => $g->valid_from?->toDateString(), 'valid_to' => $g->valid_to?->toDateString(), 'reason' => $g->reason,
        ]);
    }

    public function createGrant(Request $r)
    {
        $r->validate(['proxy_user_id' => 'required|exists:users,id', 'staff_ids' => 'required|array']);
        AttendanceService::createGrant($r->user(), User::findOrFail($r->proxy_user_id), $r->staff_ids, $r->valid_from, $r->valid_to, $r->reason);

        return ['message' => 'Proxy permission saved'];
    }

    public function revokeGrant(Request $r, ProxyGrant $grant)
    {
        if ($grant->manager_id !== $r->user()->id && ! $r->user()->is_super_admin) {
            throw new BizException('Only the manager who gave it can revoke.');
        }
        $grant->update(['active' => false]);
        \App\Services\Audit::log('attendance.proxy_grant_revoked', $grant);

        return ['message' => 'Revoked'];
    }

    public function correction(Request $r)
    {
        $r->validate(['date' => 'required|date', 'reason' => 'required|string']);
        AttendanceService::requestCorrection($r->user(), $r->date, $r->check_in, $r->check_out, $r->reason);

        return ['message' => 'Correction sent for approval'];
    }

    public function remoteDecision(Request $r, Attendance $attendance)
    {
        AttendanceService::decideRemote($attendance, $r->user(), $r->boolean('approve'));

        return ['message' => 'Saved'];
    }

    public function otDecision(Request $r, Attendance $attendance)
    {
        AttendanceService::decideOvertime($attendance, $r->user(), $r->boolean('approve'));

        return ['message' => 'Saved'];
    }
}
