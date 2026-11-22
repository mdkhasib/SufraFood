<?php

namespace Database\Seeders;

use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\ShiftTemplate;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class HistorySeeder extends Seeder
{
    /** Demo login accounts: left un-checked-in today so testers can do it themselves. */
    public const DEMO_LOGINS = ['SA-0001', 'E-1001', 'E-1002', 'E-1004', 'E-1010', 'E-1020', 'E-1030', 'E-1031', 'E-1040', 'E-1050', 'E-1060', 'E-1070', 'E-2001', 'E-2004', 'E-2007', 'E-2008', 'E-2011', 'E-2009', 'E-6001'];

    public function run(): void
    {
        mt_srand(42);
        $sh = ShiftTemplate::all()->keyBy('code');
        $users = User::with('role', 'location')->where('is_super_admin', false)->get();
        $start = now()->subMonthNoOverflow()->startOfMonth();
        $end = today()->addDays(13);
        $holidays = Holiday::pluck('date')->map(fn ($d) => Carbon::parse($d)->toDateString())->all();

        // ---------- rosters ----------
        $gulToday = ['E-2001' => 'G', 'E-2002' => 'M', 'E-2003' => 'G', 'E-2004' => 'M', 'E-2005' => 'M', 'E-2006' => 'E', 'E-2007' => 'M', 'E-2008' => 'S', 'E-2009' => 'M', 'E-2010' => 'S', 'E-2011' => 'G', 'E-2012' => 'S', 'E-2013' => 'E', 'E-2014' => 'OFF', 'E-2015' => 'E', 'E-2016' => 'M', 'E-2017' => 'GD', 'E-2018' => 'M'];
        $rows = [];
        $rosterOf = [];
        foreach ($users as $u) {
            if ($u->role?->key === 'owner') {
                continue;
            }
            $code = $this->shiftFor($u);
            $offDay = $u->location->type === 'OFFICE' ? 5 : ($u->id % 7);
            for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
                $s = $code;
                $off = $d->dayOfWeek === $offDay;
                if (isset($gulToday[$u->employee_code]) && in_array($u->role?->key, ['worker'])) {
                    $s = ['M', 'E', 'S'][($d->dayOfYear + $u->id) % 3];
                    if ($u->employee_code === 'E-2016' || $u->employee_code === 'E-2009') {
                        $s = 'M';
                    }
                    if ($u->employee_code === 'E-2017') {
                        $s = 'GD';
                    }
                }
                if ($d->isToday() && isset($gulToday[$u->employee_code])) {
                    $off = $gulToday[$u->employee_code] === 'OFF';
                    $s = $off ? $s : $gulToday[$u->employee_code];
                }
                if ($d->isToday() && in_array($u->employee_code, self::DEMO_LOGINS)) {
                    $off = false; // demo logins always work today
                }
                $rows[] = ['user_id' => $u->id, 'date' => $d->toDateString(), 'shift_template_id' => $off ? null : $sh[$s]->id, 'location_id' => $u->location_id, 'is_off' => $off, 'no_show_notified' => $d->lt(today()), 'created_at' => now(), 'updated_at' => now()];
                $rosterOf[$u->id][$d->toDateString()] = $off ? null : $sh[$s];
            }
        }
        foreach (array_chunk($rows, 1000) as $c) {
            DB::table('rosters')->insert($c);
        }

        // ---------- attendance history (past days) + today's scenario ----------
        $att = [];
        $loc = fn (User $u) => $u->location;
        foreach ($users as $u) {
            foreach ($rosterOf[$u->id] ?? [] as $date => $shift) {
                $d = Carbon::parse($date);
                if (! $shift || $d->gt(today()) || in_array($date, $holidays)) {
                    continue;
                }
                if ($d->isToday()) {
                    continue; // handled below
                }
                $r = mt_rand(1, 100);
                if ($r <= 3) {
                    continue; // absent
                }
                $startAt = Carbon::parse("$date {$shift->start_time}");
                $late = $r <= 13 ? mt_rand(11, 32) : 0;
                if ($u->employee_code === 'E-2005' && $d->day % 9 === 0) {
                    $late = mt_rand(12, 20); // Karim is late now and then
                }
                $in = $late ? $startAt->copy()->addMinutes($late) : $startAt->copy()->subMinutes(mt_rand(0, 15))->addMinutes(mt_rand(0, 8));
                $endAt = Carbon::parse("$date ".($shift->end_time2 ?? $shift->end_time));
                $otMin = mt_rand(1, 100) <= 6 ? mt_rand(45, 120) : 0;
                $out = $endAt->copy()->addMinutes($otMin ?: mt_rand(0, 25));
                $method = in_array($u->employee_code, ['E-2007', 'E-2016']) && mt_rand(1, 4) === 1 ? 'PROXY' : 'SELF';
                $by = $method === 'PROXY' ? User::where('employee_code', $u->employee_code === 'E-2007' ? 'E-2004' : 'E-2002')->value('id') : null;
                $l = $loc($u);
                $att[] = [
                    'user_id' => $u->id, 'date' => $date, 'location_id' => $u->location_id, 'check_in_at' => $in, 'check_out_at' => $out,
                    'in_lat' => $l->lat + mt_rand(-20, 20) / 100000, 'in_lng' => $l->lng + mt_rand(-20, 20) / 100000, 'in_accuracy' => mt_rand(5, 30), 'in_distance_m' => mt_rand(3, 40),
                    'method' => $method, 'by_user_id' => $by, 'status' => $late > 10 ? 'LATE' : 'PRESENT', 'late_minutes' => $late > 10 ? $late : 0,
                    'worked_minutes' => (int) $in->diffInMinutes($out), 'ot_minutes' => $otMin, 'ot_status' => $otMin ? 'APPROVED' : 'NONE',
                    'created_at' => $in, 'updated_at' => $out,
                ];
            }
        }
        // today: people whose shift already started have checked in (except demo logins & scenario people)
        $now = now();
        $scenario = ['E-2002' => [-5, 'SELF'], 'E-2003' => [4, 'SELF'], 'E-2005' => [17, 'SELF'], 'E-2016' => [-10, 'PROXY'], 'E-2017' => [-15, 'SELF'], 'E-2018' => [-1, 'SELF'], 'E-2010' => [2, 'SELF'], 'E-2012' => [6, 'SELF']];
        foreach ($users as $u) {
            $shift = $rosterOf[$u->id][today()->toDateString()] ?? null;
            if (! $shift || in_array($u->employee_code, self::DEMO_LOGINS)) {
                continue;
            }
            $startAt = Carbon::parse(today()->toDateString().' '.$shift->start_time);
            if (isset($scenario[$u->employee_code])) {
                [$off, $method] = $scenario[$u->employee_code];
            } else {
                if (mt_rand(1, 100) <= 7) {
                    continue;
                }
                $off = mt_rand(1, 100) <= 10 ? mt_rand(11, 25) : -mt_rand(0, 15);
                $method = 'SELF';
            }
            $in = $startAt->copy()->addMinutes($off);
            if ($in->gt($now)) {
                continue;
            }
            $l = $u->location;
            $att[] = [
                'user_id' => $u->id, 'date' => today()->toDateString(), 'location_id' => $u->location_id, 'check_in_at' => $in, 'check_out_at' => null,
                'in_lat' => $l->lat, 'in_lng' => $l->lng, 'in_accuracy' => 12, 'in_distance_m' => mt_rand(3, 30),
                'method' => $method, 'by_user_id' => $method === 'PROXY' ? User::where('employee_code', 'E-2002')->value('id') : null,
                'status' => $off > 10 ? 'LATE' : 'PRESENT', 'late_minutes' => $off > 10 ? $off : 0, 'worked_minutes' => 0, 'ot_minutes' => 0, 'ot_status' => 'NONE',
                'created_at' => $in, 'updated_at' => $in,
            ];
        }
        foreach (array_chunk($att, 800) as $c) {
            DB::table('attendances')->insert($c);
        }

        // Alamgir: sick today (approved), a past casual leave for Belal
        $sick = LeaveType::where('name', 'Sick Leave')->value('id');
        $casual = LeaveType::where('name', 'Casual Leave')->value('id');
        LeaveRequest::create(['user_id' => User::where('employee_code', 'E-2009')->value('id'), 'leave_type_id' => $sick, 'from_date' => today(), 'to_date' => today(), 'days' => 1, 'reason' => 'Fever (manager called)', 'status' => 'APPROVED']);
        LeaveRequest::create(['user_id' => User::where('employee_code', 'E-2006')->value('id'), 'leave_type_id' => $casual, 'from_date' => today()->subDays(12), 'to_date' => today()->subDays(11), 'days' => 2, 'reason' => 'Family program', 'status' => 'APPROVED']);

        // ---------- task history (feeds KPI) ----------
        $pool = [
            ['Cut onions', 'পেঁয়াজ কাটা'], ['Marinate chicken', 'মুরগি মেরিনেট'], ['Clean prep table', 'প্রেপ টেবিল পরিষ্কার'], ['Wash rice', 'চাল ধোয়া'],
            ['Set up tables', 'টেবিল সাজানো'], ['Restock napkins & tissue', 'টিস্যু রিস্টক'], ['Check freezer temperature', 'ফ্রিজারের তাপমাত্রা'], ['Wash dishes batch', 'থালা ধোয়া'],
            ['Prepare salad', 'সালাদ তৈরি'], ['Count gas cylinders', 'গ্যাস সিলিন্ডার গণনা'], ['Clean washroom', 'ওয়াশরুম পরিষ্কার'], ['Pack takeaway boxes', 'টেকঅ্যাওয়ে প্যাক'],
        ];
        $tasks = [];
        $workers = $users->filter(fn ($u) => in_array($u->role?->key, ['worker', 'supervisor', 'storekeeper', 'cashier', 'driver']));
        $presentDays = collect($att)->groupBy('user_id')->map(fn ($g) => collect($g)->pluck('date')->all());
        foreach ($workers as $u) {
            $assigner = $u->manager_id ?? $u->id;
            foreach ($presentDays[$u->id] ?? [] as $date) {
                if ($date === today()->toDateString()) {
                    continue;
                }
                $shift = $rosterOf[$u->id][$date] ?? null;
                if (! $shift) {
                    continue;
                }
                $n = mt_rand(2, 3);
                for ($i = 0; $i < $n; $i++) {
                    [$t, $tb] = $pool[mt_rand(0, count($pool) - 1)];
                    $due = Carbon::parse("$date {$shift->start_time}")->addMinutes(mt_rand(60, 360));
                    $r = mt_rand(1, 100);
                    $skill = $u->employee_code === 'E-2005' ? 3 : 0; // Karim slightly slower
                    $done = $r <= (88 - $skill) ? $due->copy()->subMinutes(mt_rand(5, 80)) : $due->copy()->addMinutes(mt_rand(10, 70));
                    $tasks[] = [
                        'title' => $t, 'title_bn' => $tb, 'assigned_by' => $assigner, 'assignee_id' => $u->id, 'location_id' => $u->location_id,
                        'priority' => 'NORMAL', 'due_at' => $due, 'proof_required' => false, 'status' => 'DONE',
                        'started_at' => $done->copy()->subMinutes(30), 'submitted_at' => $done, 'completed_at' => $done,
                        'rework_count' => mt_rand(1, 100) <= 3 ? 1 : 0, 'escalated' => false, 'created_at' => $due->copy()->subHours(3), 'updated_at' => $done,
                    ];
                }
            }
        }
        foreach (array_chunk($tasks, 800) as $c) {
            DB::table('tasks')->insert($c);
        }
        $this->command?->info('History: '.count($rows).' roster days, '.count($att).' attendance, '.count($tasks).' tasks');
    }

    protected function shiftFor(User $u): string
    {
        $role = $u->role?->key;
        $type = $u->location->type;
        if ($type === 'OFFICE') {
            return 'O';
        }
        if ($type === 'CENTRAL_KITCHEN') {
            return match ($role) {
                'ck_head_chef' => 'G', 'driver' => 'M', 'supervisor' => 'K',
                default => str_contains((string) $u->position, 'Cook') ? 'K' : 'M',
            };
        }
        if ($type === 'WAREHOUSE' || $type === 'CATERING') {
            return $role === 'worker' && $type === 'CATERING' ? 'G' : ($type === 'WAREHOUSE' ? 'M' : 'G');
        }

        return match ($role) {
            'branch_manager', 'head_chef', 'cashier' => 'G',
            'supervisor', 'storekeeper' => 'M',
            default => ['M', 'E', 'S'][$u->id % 3],
        };
    }
}
