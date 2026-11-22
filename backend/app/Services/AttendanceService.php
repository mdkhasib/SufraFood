<?php

namespace App\Services;

use App\Exceptions\BizException;
use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\CateringEvent;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\Location;
use App\Models\ProxyGrant;
use App\Models\Roster;
use App\Models\User;
use App\Support\Features;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class AttendanceService
{
    public static function distance(float $lat1, float $lng1, float $lat2, float $lng2): int
    {
        $r = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return (int) round($r * 2 * atan2(sqrt($a), sqrt(1 - $a)));
    }

    public static function rosterFor(User $u, $date = null): ?Roster
    {
        return Roster::with('shift')->where('user_id', $u->id)->whereDate('date', $date ?? today())->first();
    }

    public static function onLeave(int $userId, $date): bool
    {
        $d = Carbon::parse($date)->toDateString();

        return LeaveRequest::where('user_id', $userId)->where('status', 'APPROVED')
            ->whereDate('from_date', '<=', $d)->whereDate('to_date', '>=', $d)->exists();
    }

    /** Find where this check-in is valid. Returns [locationId, distance, label]. */
    protected static function resolvePlace(User $u, ?Roster $roster, float $lat, float $lng): array
    {
        $loc = Location::find($roster?->location_id ?? $u->location_id);
        if (! $loc || $loc->lat === null) {
            throw new BizException('Your work location has no GPS area set. Ask HR.');
        }
        $dist = static::distance($lat, $lng, (float) $loc->lat, (float) $loc->lng);
        if ($dist <= $loc->radius_m) {
            return [$loc->id, $dist, $loc->name, true];
        }
        // catering event venue (temporary geofence) for assigned staff today
        $events = CateringEvent::whereDate('event_at', today())->whereIn('status', ['CONFIRMED', 'DELIVERED'])
            ->whereHas('staff', fn ($q) => $q->where('users.id', $u->id))->get();
        foreach ($events as $ev) {
            if ($ev->lat !== null) {
                $d = static::distance($lat, $lng, (float) $ev->lat, (float) $ev->lng);
                if ($d <= $ev->radius_m) {
                    return [$u->location_id, $d, 'Event: '.$ev->venue, true];
                }
            }
        }

        if ((int) Features::setting('attendance.test_mode', 0)) {
            // Super Admin test mode: geofence not enforced (distance still recorded)
            return [$loc->id, $dist, $loc->name.' (test mode)', true];
        }

        return [$loc->id, $dist, $loc->name, false];
    }

    protected static function guardGps(User $actor, ?float $accuracy, bool $mocked): void
    {
        if ($mocked) {
            Audit::log('attendance.mock_gps_blocked', $actor);
            Notifier::send(array_filter([$actor->manager_id]), 'Fake GPS blocked', $actor->name.' tried to check in with a mock location app.', 'warning');
            throw new BizException('Fake GPS / mock location detected. Check-in blocked.');
        }
        $max = (float) Features::setting('attendance.max_accuracy_m', 150);
        if ($accuracy !== null && $accuracy > $max) {
            throw new BizException('GPS signal is weak (±'.round($accuracy).' m). Move to an open spot and try again.');
        }
    }

    protected static function guardDevice(User $u, ?string $deviceId): void
    {
        if (! (int) Features::setting('attendance.device_binding', 0) || ! $deviceId) {
            return;
        }
        if (! $u->device_id) {
            $u->update(['device_id' => $deviceId]);
        } elseif ($u->device_id !== $deviceId) {
            throw new BizException('This phone is not registered for you. Ask HR to reset your device.');
        }
    }

    protected static function lateMinutes(?Roster $roster, Carbon $at): int
    {
        if (! $roster || ! $roster->shift || $roster->is_off) {
            return 0;
        }
        $start = Carbon::parse($roster->date->toDateString().' '.$roster->shift->start_time);
        $grace = (int) Features::setting('attendance.grace_minutes', 10);
        $late = (int) floor($start->diffInMinutes($at, false));

        return $late > $grace ? $late : 0;
    }

    public static function checkIn(User $u, float $lat, float $lng, ?float $accuracy = null, bool $mocked = false, ?string $deviceId = null, bool $remote = false, ?string $reason = null): Attendance
    {
        if (! $u->hasPerm('attendance.self')) {
            throw new BizException('Attendance is not enabled for you.');
        }
        static::guardGps($u, $accuracy, $mocked);
        static::guardDevice($u, $deviceId);
        if (Attendance::where('user_id', $u->id)->whereDate('date', today())->exists()) {
            throw new BizException('You have already checked in today.');
        }
        $roster = static::rosterFor($u);
        [$locId, $dist, $label, $inside] = static::resolvePlace($u, $roster, $lat, $lng);
        $method = 'SELF';
        $remoteStatus = null;
        if (! $inside) {
            if ($remote && $u->featureOn('attendance.remote_checkin')) {
                if (! $reason) {
                    throw new BizException('Give a reason for remote check-in.');
                }
                $method = 'REMOTE';
                $remoteStatus = 'PENDING';
            } else {
                throw new BizException("You are {$dist} m from {$label}. Check in only works inside the work area.");
            }
        }
        $now = now();
        $late = static::lateMinutes($roster, $now);
        $att = Attendance::create([
            'user_id' => $u->id, 'date' => today(), 'location_id' => $locId, 'check_in_at' => $now,
            'in_lat' => $lat, 'in_lng' => $lng, 'in_accuracy' => $accuracy, 'in_distance_m' => $dist,
            'method' => $method, 'remote_status' => $remoteStatus, 'status' => $late ? 'LATE' : 'PRESENT', 'late_minutes' => $late,
            'device_id' => $deviceId, 'note' => $reason,
        ]);
        static::alertArrival($u, $att, $label);

        return $att;
    }

    protected static function alertArrival(User $u, Attendance $att, string $label, ?User $proxy = null): void
    {
        $time = $att->check_in_at->format('h:i A');
        $lead = $u->manager_id;
        $locMgr = Location::find($u->location_id)?->manager_id;
        $how = $proxy ? " (proxy by {$proxy->name})" : ($att->method === 'REMOTE' ? ' (REMOTE – needs approval)' : '');
        if ($att->status === 'LATE') {
            Notifier::send(array_filter([$lead, $locMgr]), "⚠ {$u->name} checked in late", "{$label} · {$time} · late {$att->late_minutes} min{$how}", 'warning', '/attendance/team');
        } elseif ($u->featureOn('attendance.arrival_alert') && (Features::setting('attendance.arrival_alert_mode', 'EVERY') === 'EVERY' || $proxy || $att->method === 'REMOTE')) {
            Notifier::send(array_filter([$lead]), "{$u->name} checked in", "{$label} · {$time} · on time{$how}", 'attendance', '/attendance/team');
        }
        if ($proxy && $locMgr && $locMgr !== $lead) {
            Notifier::send($locMgr, "{$proxy->name} checked in {$u->name} (proxy)", "{$label} · {$time}", 'attendance', '/attendance/team');
        }
        if ($att->method === 'REMOTE') {
            Notifier::send(array_filter([$lead, $locMgr]), "Remote check-in: {$u->name}", $att->note, 'approval', '/attendance/team');
        }
    }

    public static function checkOut(User $u, float $lat, float $lng, ?float $accuracy = null, bool $mocked = false, ?User $by = null): Attendance
    {
        static::guardGps($by ?? $u, $accuracy, $mocked);
        $att = Attendance::where('user_id', $u->id)->whereDate('date', today())->first();
        if (! $att || ! $att->check_in_at) {
            throw new BizException('Not checked in today.');
        }
        if ($att->check_out_at) {
            throw new BizException('Already checked out.');
        }
        $roster = static::rosterFor($u);
        if ($att->method !== 'REMOTE') {
            [, $dist, $label, $inside] = static::resolvePlace($u, $roster, $lat, $lng);
            if (! $inside) {
                throw new BizException("You are {$dist} m from {$label}. Check out only works inside the work area.");
            }
        }
        static::closeAttendance($att, now(), $roster);
        $att->update(['out_lat' => $lat, 'out_lng' => $lng, 'out_by_user_id' => $by?->id]);

        return $att->fresh();
    }

    protected static function closeAttendance(Attendance $att, Carbon $out, ?Roster $roster, bool $auto = false): void
    {
        $worked = max(0, (int) $att->check_in_at->diffInMinutes($out));
        $planned = $roster?->shift ? $roster->shift->plannedMinutes() : 480;
        $ot = max(0, $worked - $planned - (int) ($roster?->shift?->break_minutes ?? 0));
        $otStatus = ($ot >= 30 && $att->user && $att->user->featureOn('attendance.overtime')) ? 'PENDING' : 'NONE';
        $att->update(['check_out_at' => $out, 'worked_minutes' => $worked, 'ot_minutes' => $otStatus === 'PENDING' ? $ot : 0, 'ot_status' => $otStatus, 'auto_closed' => $auto]);
    }

    // ---------------- Proxy ----------------

    public static function activeGrantsFor(User $proxy): Collection
    {
        $d = today()->toDateString();

        return ProxyGrant::with('staff.role', 'manager')->where('proxy_user_id', $proxy->id)->where('active', true)
            ->where(fn ($q) => $q->whereNull('valid_from')->orWhereDate('valid_from', '<=', $d))
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $d))->get();
    }

    public static function proxyList(User $proxy): Collection
    {
        return static::activeGrantsFor($proxy)->flatMap(fn ($g) => $g->staff)->unique('id')->values();
    }

    public static function proxyCheckIn(User $proxy, User $target, float $lat, float $lng, ?float $accuracy = null, bool $mocked = false): Attendance
    {
        if (! $proxy->featureOn('attendance.proxy_checkin')) {
            throw new BizException('Proxy check-in is not enabled.');
        }
        $grant = static::activeGrantsFor($proxy)->first(fn ($g) => $g->staff->contains('id', $target->id));
        if (! $grant) {
            throw new BizException("You are not allowed to check in {$target->name}.");
        }
        $own = Attendance::where('user_id', $proxy->id)->whereDate('date', today())->first();
        if (! $own || ! $own->check_in_at || $own->check_out_at) {
            throw new BizException('Check yourself in first.');
        }
        static::guardGps($proxy, $accuracy, $mocked);
        if (Attendance::where('user_id', $target->id)->whereDate('date', today())->exists()) {
            throw new BizException("{$target->name} is already checked in.");
        }
        $roster = static::rosterFor($target);
        [$locId, $dist, $label, $inside] = static::resolvePlace($target, $roster, $lat, $lng);
        if (! $inside) {
            throw new BizException("You are {$dist} m from {$label}. Proxy check-in only works inside the work area.");
        }
        $now = now();
        $late = static::lateMinutes($roster, $now);
        $att = Attendance::create([
            'user_id' => $target->id, 'date' => today(), 'location_id' => $locId, 'check_in_at' => $now,
            'in_lat' => $lat, 'in_lng' => $lng, 'in_accuracy' => $accuracy, 'in_distance_m' => $dist,
            'method' => 'PROXY', 'by_user_id' => $proxy->id, 'status' => $late ? 'LATE' : 'PRESENT', 'late_minutes' => $late,
        ]);
        Audit::log('attendance.proxy_checkin', $att, null, ['proxy' => $proxy->id, 'grant' => $grant->id]);
        static::alertArrival($target, $att, $label, $proxy);
        Notifier::send(array_unique(array_filter([$grant->manager_id])), "{$proxy->name} checked in {$target->name} (proxy)", $now->format('h:i A'), 'attendance', '/attendance/team');
        Notifier::send($target, 'You were checked in by '.$proxy->name, $now->format('h:i A'), 'attendance');

        return $att;
    }

    public static function proxyCheckOut(User $proxy, User $target, float $lat, float $lng, ?float $accuracy = null, bool $mocked = false): Attendance
    {
        if (! static::proxyList($proxy)->contains('id', $target->id)) {
            throw new BizException("You are not allowed to check out {$target->name}.");
        }

        return static::checkOut($target, $lat, $lng, $accuracy, $mocked, $proxy);
    }

    public static function createGrant(User $manager, User $proxy, array $staffIds, ?string $from = null, ?string $to = null, ?string $reason = null): ProxyGrant
    {
        if (! $manager->hasPerm('attendance.proxy_grant')) {
            throw new BizException('You cannot grant proxy check-in.');
        }
        $max = (int) Features::setting('attendance.proxy_max', 2);
        $staffIds = array_values(array_unique(array_map('intval', $staffIds)));
        if (! $staffIds || count($staffIds) > $max) {
            throw new BizException("A proxy can check in 1 to {$max} people.");
        }
        $team = $manager->isGroupWide() ? null : array_merge($manager->subordinateIds(), $manager->scopedUserIds());
        foreach (array_merge($staffIds, [$proxy->id]) as $id) {
            if ($team !== null && ! in_array($id, $team)) {
                throw new BizException('You can only use people from your own team.');
            }
        }
        if (in_array($proxy->id, $staffIds)) {
            throw new BizException('A person cannot be their own proxy.');
        }
        $g = ProxyGrant::create(['manager_id' => $manager->id, 'proxy_user_id' => $proxy->id, 'valid_from' => $from, 'valid_to' => $to, 'reason' => $reason, 'active' => true]);
        $g->staff()->sync($staffIds);
        Audit::log('attendance.proxy_grant_created', $g, null, ['proxy' => $proxy->id, 'staff' => $staffIds]);
        Notifier::send($proxy, 'Proxy check-in permission', 'You can check in: '.User::whereIn('id', $staffIds)->pluck('name')->join(', '), 'info');

        return $g;
    }

    // ---------------- Corrections, remote, OT ----------------

    public static function requestCorrection(User $u, string $date, ?string $in, ?string $out, string $reason): AttendanceCorrection
    {
        if (! $u->hasPerm('attendance.correction_request')) {
            throw new BizException('Correction requests are not enabled.');
        }
        if (Carbon::parse($date)->isFuture()) {
            throw new BizException('Cannot correct a future date.');
        }
        $c = AttendanceCorrection::create(['user_id' => $u->id, 'date' => $date, 'check_in' => $in, 'check_out' => $out, 'reason' => $reason]);
        ApprovalService::start($c, $u);

        return $c;
    }

    public static function onCorrectionDecided(AttendanceCorrection $c, bool $approved, User $by): void
    {
        $c->update(['status' => $approved ? 'APPROVED' : 'REJECTED']);
        if ($approved) {
            $u = $c->user;
            $roster = static::rosterFor($u, $c->date);
            $att = Attendance::where('user_id', $u->id)->whereDate('date', $c->date)->first() ?? new Attendance(['user_id' => $u->id, 'date' => $c->date->toDateString()]);
            $att->location_id = $att->location_id ?? $u->location_id;
            $att->method = 'CORRECTION';
            if ($c->check_in) {
                $att->check_in_at = Carbon::parse($c->date->toDateString().' '.$c->check_in);
                $late = static::lateMinutes($roster, $att->check_in_at);
                $att->late_minutes = $late;
                $att->status = $late ? 'LATE' : 'PRESENT';
            }
            $att->save();
            if ($c->check_out && $att->check_in_at) {
                static::closeAttendance($att, Carbon::parse($c->date->toDateString().' '.$c->check_out), $roster);
            }
        }
        Notifier::send($c->user_id, 'Attendance correction '.($approved ? 'approved' : 'rejected'), $c->date->format('d M'), $approved ? 'success' : 'warning');
    }

    public static function decideRemote(Attendance $att, User $by, bool $approve): void
    {
        if (! $by->canManageUser($att->user) && ! $by->is_super_admin) {
            throw new BizException('Not your team member.');
        }
        $att->update(['remote_status' => $approve ? 'APPROVED' : 'REJECTED']);
        Notifier::send($att->user_id, 'Remote check-in '.($approve ? 'approved' : 'rejected'), null, $approve ? 'success' : 'warning');
        if (! $approve) {
            $att->delete();
        }
    }

    public static function decideOvertime(Attendance $att, User $by, bool $approve): void
    {
        if (! $by->canManageUser($att->user) && ! $by->is_super_admin) {
            throw new BizException('Not your team member.');
        }
        $att->update(['ot_status' => $approve ? 'APPROVED' : 'REJECTED']);
    }

    // ---------------- Status & boards ----------------

    /** Status for one user on one date: IN, LATE, OUT, NOT_ARRIVED, NOT_DUE, OFF, LEAVE, HOLIDAY, ABSENT, NO_SHIFT, PRESENT */
    public static function dayStatus(User $u, $date, ?Roster $roster = null, ?Attendance $att = null): array
    {
        $date = Carbon::parse($date)->startOfDay();
        $roster = $roster ?? static::rosterFor($u, $date);
        $att = $att ?? Attendance::where('user_id', $u->id)->whereDate('date', $date)->first();
        $shiftLabel = $roster?->shift?->label();
        if ($att && $att->check_in_at) {
            $st = $att->check_out_at ? 'OUT' : ($att->status === 'LATE' ? 'LATE' : 'IN');
            if (! $date->isToday()) {
                $st = $att->status === 'LATE' ? 'LATE' : 'PRESENT';
            }

            return ['status' => $st, 'shift' => $shiftLabel, 'attendance' => $att];
        }
        if (static::onLeave($u->id, $date)) {
            return ['status' => 'LEAVE', 'shift' => $shiftLabel, 'attendance' => null];
        }
        if ($roster?->is_off) {
            return ['status' => 'OFF', 'shift' => null, 'attendance' => null];
        }
        if (Holiday::whereDate('date', $date)->exists()) {
            return ['status' => 'HOLIDAY', 'shift' => null, 'attendance' => null];
        }
        if (! $roster || ! $roster->shift) {
            return ['status' => 'NO_SHIFT', 'shift' => null, 'attendance' => null];
        }
        $start = Carbon::parse($date->toDateString().' '.$roster->shift->start_time);
        if ($date->isToday()) {
            $grace = (int) Features::setting('attendance.grace_minutes', 10);

            return ['status' => now()->gt($start->copy()->addMinutes($grace)) ? 'NOT_ARRIVED' : 'NOT_DUE', 'shift' => $shiftLabel, 'attendance' => null];
        }

        return ['status' => $date->isPast() ? 'ABSENT' : 'SCHEDULED', 'shift' => $shiftLabel, 'attendance' => null];
    }

    public static function teamBoard(User $manager, $date = null): array
    {
        $ids = $manager->isGroupWide() ? User::where('status', 'ACTIVE')->pluck('id')->all() : array_unique(array_merge($manager->subordinateIds(), $manager->scopedUserIds()));
        $users = User::with('role', 'location')->whereIn('id', $ids)->where('id', '!=', $manager->id)->orderBy('location_id')->orderBy('name')->get();
        $date = $date ?? today();
        $rows = [];
        foreach ($users as $u) {
            $s = static::dayStatus($u, $date);
            $a = $s['attendance'];
            $rows[] = [
                'user' => $u, 'status' => $s['status'], 'shift' => $s['shift'],
                'check_in' => $a?->check_in_at?->format('h:i A'), 'check_out' => $a?->check_out_at?->format('h:i A'),
                'method' => $a?->method, 'by' => $a?->byUser?->name, 'late_minutes' => $a?->late_minutes,
                'attendance_id' => $a?->id, 'remote_status' => $a?->remote_status, 'ot_status' => $a?->ot_status, 'ot_minutes' => $a?->ot_minutes,
            ];
        }
        $counts = collect($rows)->countBy('status')->all();

        return ['rows' => $rows, 'counts' => $counts];
    }

    public static function monthCalendar(User $u, string $month): array
    {
        $start = Carbon::parse($month.'-01');
        $days = [];
        $rosters = Roster::with('shift')->where('user_id', $u->id)->whereBetween('date', [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()])->get()->keyBy(fn ($r) => $r->date->toDateString());
        $atts = Attendance::where('user_id', $u->id)->whereBetween('date', [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()])->get()->keyBy(fn ($a) => $a->date->toDateString());
        for ($d = $start->copy(); $d->month === $start->month; $d->addDay()) {
            $key = $d->toDateString();
            if ($d->isFuture() && ! $d->isToday()) {
                $r = $rosters[$key] ?? null;
                $days[] = ['date' => $key, 'status' => $r ? ($r->is_off ? 'OFF' : 'SCHEDULED') : 'NO_SHIFT', 'shift' => $r?->shift?->label()];
                continue;
            }
            $s = static::dayStatus($u, $d, $rosters[$key] ?? null, $atts[$key] ?? null);
            $a = $s['attendance'];
            $days[] = ['date' => $key, 'status' => $s['status'], 'shift' => $s['shift'], 'in' => $a?->check_in_at?->format('H:i'), 'out' => $a?->check_out_at?->format('H:i'), 'late' => $a?->late_minutes, 'method' => $a?->method];
        }
        $summary = collect($days)->countBy('status')->all();

        return ['days' => $days, 'summary' => $summary];
    }

    // ---------------- Scheduled checks ----------------

    public static function noShowCheck(): int
    {
        $n = 0;
        $grace = (int) Features::setting('attendance.grace_minutes', 10);
        foreach (Roster::with('shift', 'user')->whereDate('date', today())->where('is_off', false)->where('no_show_notified', false)->whereNotNull('shift_template_id')->get() as $r) {
            if (! $r->user || ! $r->user->featureOn('attendance.no_show_alert')) {
                continue;
            }
            $start = Carbon::parse(today()->toDateString().' '.$r->shift->start_time)->addMinutes($grace);
            if (now()->lt($start)) {
                continue;
            }
            if (Attendance::where('user_id', $r->user_id)->whereDate('date', today())->exists() || static::onLeave($r->user_id, today())) {
                $r->update(['no_show_notified' => true]);
                continue;
            }
            $locMgr = Location::find($r->location_id ?? $r->user->location_id)?->manager_id;
            Notifier::send(array_filter([$r->user->manager_id, $locMgr]), "🔴 {$r->user->name} not checked in", 'Shift '.substr($r->shift->start_time, 0, 5).' · '.$r->shift->name, 'warning', '/attendance/team');
            $r->update(['no_show_notified' => true]);
            $n++;
        }

        return $n;
    }

    public static function autoClose(): int
    {
        $n = 0;
        foreach (Attendance::with('user')->whereNotNull('check_in_at')->whereNull('check_out_at')->get() as $att) {
            $roster = Roster::with('shift')->where('user_id', $att->user_id)->whereDate('date', $att->date)->first();
            $end = $roster?->shift
                ? Carbon::parse($att->date->toDateString().' '.($roster->shift->end_time2 ?? $roster->shift->end_time))
                : $att->check_in_at->copy()->addHours(9);
            if ($end->lt($att->check_in_at)) {
                $end->addDay();
            }
            if (now()->gt($end->copy()->addHours(2))) {
                static::closeAttendance($att, $end, $roster, true);
                Notifier::send(array_filter([$att->user?->manager_id]), "Auto check-out: {$att->user?->name}", 'Forgot to check out on '.$att->date->format('d M'), 'warning');
                $n++;
            }
        }

        return $n;
    }
}
