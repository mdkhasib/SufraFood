<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Location;
use App\Models\ProxyGrant;
use App\Models\Roster;
use App\Models\ShiftTemplate;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AttendanceController extends Controller
{
    public function team(Request $r)
    {
        $date = $r->input('date', today()->toDateString());
        $board = AttendanceService::teamBoard($r->user(), $date);
        if ($r->filled('location_id')) {
            $board['rows'] = array_values(array_filter($board['rows'], fn ($x) => $x['user']->location_id == $r->location_id));
            $board['counts'] = collect($board['rows'])->countBy('status')->all();
        }
        $locations = $r->user()->isGroupWide() ? Location::orderBy('name')->get() : collect();

        return view('attendance.team', compact('board', 'date', 'locations'));
    }

    public function decide(Request $r, Attendance $attendance, string $what)
    {
        $approve = $r->input('decision') === 'approve';
        $what === 'remote' ? AttendanceService::decideRemote($attendance, $r->user(), $approve) : AttendanceService::decideOvertime($attendance, $r->user(), $approve);

        return back()->with('ok', 'Saved');
    }

    public function report(Request $r)
    {
        $u = $r->user();
        $month = $r->input('month', now()->format('Y-m'));
        $ids = $u->isGroupWide() ? User::where('is_super_admin', false)->pluck('id')->all() : array_unique(array_merge($u->subordinateIds(), $u->scopedUserIds()));
        $q = User::with('location')->whereIn('id', $ids)->where('status', 'ACTIVE');
        $locId = $r->input('location_id', $u->isGroupWide() ? Location::where('code', 'BR-GUL')->value('id') : null);
        if ($locId) {
            $q->where('location_id', $locId);
        }
        $rows = $q->orderBy('name')->get()->map(fn ($p) => ['user' => $p] + AttendanceService::monthCalendar($p, $month));
        $locations = $u->isGroupWide() ? Location::orderBy('name')->get() : collect();

        return view('attendance.report', compact('rows', 'month', 'locations', 'locId'));
    }

    public function me(Request $r)
    {
        $month = $r->input('month', now()->format('Y-m'));
        $cal = AttendanceService::monthCalendar($r->user(), $month);
        $roster = Roster::with('shift')->where('user_id', $r->user()->id)->whereBetween('date', [today(), today()->addDays(13)])->orderBy('date')->get();

        return view('attendance.me', compact('cal', 'month', 'roster'));
    }

    public function roster(Request $r)
    {
        $u = $r->user();
        $start = Carbon::parse($r->input('week', today()->startOfWeek(Carbon::SATURDAY)->toDateString()))->startOfDay();
        $days = collect(range(0, 6))->map(fn ($i) => $start->copy()->addDays($i));
        $locId = $u->isGroupWide() ? $r->input('location_id', Location::where('code', 'BR-GUL')->value('id')) : $u->location_id;
        $staff = User::where('location_id', $locId)->where('status', 'ACTIVE')->where('is_super_admin', false)
            ->when(! $u->isGroupWide() && $u->role?->scope === 'TEAM', fn ($q) => $q->whereIn('id', $u->subordinateIds()))
            ->orderBy('manager_id')->orderBy('name')->get();
        $rosters = Roster::whereIn('user_id', $staff->pluck('id'))->whereBetween('date', [$start->toDateString(), $start->copy()->addDays(6)->toDateString()])->get()
            ->groupBy('user_id')->map(fn ($g) => $g->keyBy(fn ($x) => $x->date->toDateString()));
        $shifts = ShiftTemplate::orderBy('start_time')->get();
        $locations = $u->isGroupWide() ? Location::orderBy('name')->get() : collect();

        return view('attendance.roster', compact('days', 'staff', 'rosters', 'shifts', 'start', 'locations', 'locId'));
    }

    public function saveRoster(Request $r)
    {
        $u = $r->user();
        $n = 0;
        foreach ($r->input('cell', []) as $userId => $days) {
            $person = User::findOrFail($userId);
            abort_unless($u->isGroupWide() || $u->canManageUser($person), 403);
            foreach ($days as $date => $val) {
                Roster::updateOrCreate(['user_id' => $userId, 'date' => $date], [
                    'shift_template_id' => $val === 'OFF' || $val === '' ? null : (int) $val, 'is_off' => $val === 'OFF',
                    'location_id' => $person->location_id,
                ]);
                $n++;
            }
            \App\Services\Notifier::send($person, 'Roster updated', 'Check your shifts for the week', 'info');
        }
        Audit::log('roster.saved', null, null, ['cells' => $n]);

        return back()->with('ok', 'Roster saved & published to staff phones');
    }

    public function copyWeek(Request $r)
    {
        $from = Carbon::parse($r->week);
        $locId = $r->user()->isGroupWide() ? $r->location_id : $r->user()->location_id;
        $ids = User::where('location_id', $locId)->pluck('id');
        foreach (Roster::whereIn('user_id', $ids)->whereBetween('date', [$from->toDateString(), $from->copy()->addDays(6)->toDateString()])->get() as $x) {
            Roster::updateOrCreate(['user_id' => $x->user_id, 'date' => $x->date->copy()->addDays(7)->toDateString()], ['shift_template_id' => $x->shift_template_id, 'is_off' => $x->is_off, 'location_id' => $x->location_id]);
        }

        return redirect()->route('roster', ['week' => $from->copy()->addDays(7)->toDateString(), 'location_id' => $locId])->with('ok', 'Copied to next week');
    }

    public function grants(Request $r)
    {
        $u = $r->user();
        $grants = ProxyGrant::with('proxy', 'staff', 'manager')->where('active', true)->when(! $u->isGroupWide(), fn ($q) => $q->where('manager_id', $u->id))->latest('id')->get();
        $team = User::whereIn('id', $u->isGroupWide() ? User::where('is_super_admin', false)->pluck('id') : array_unique(array_merge($u->subordinateIds(), $u->scopedUserIds())))->where('status', 'ACTIVE')->orderBy('name')->get();

        return view('attendance.grants', compact('grants', 'team'));
    }

    public function saveGrant(Request $r)
    {
        $r->validate(['proxy_user_id' => 'required', 'staff_ids' => 'required|array']);
        AttendanceService::createGrant($r->user(), User::findOrFail($r->proxy_user_id), $r->staff_ids, $r->valid_from, $r->valid_to, $r->reason);

        return back()->with('ok', 'Proxy permission saved');
    }

    public function revoke(Request $r, ProxyGrant $grant)
    {
        abort_unless($grant->manager_id === $r->user()->id || $r->user()->isGroupWide(), 403);
        $grant->update(['active' => false]);
        Audit::log('attendance.proxy_grant_revoked', $grant);

        return back()->with('ok', 'Revoked');
    }
}
