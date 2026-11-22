<?php

namespace App\Services;

use App\Exceptions\BizException;
use App\Models\Attendance;
use App\Models\Holiday;
use App\Models\MoneyAccount;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\Roster;
use App\Models\SalaryAdvance;
use App\Models\User;
use App\Support\Features;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PayrollService
{
    public static function generate(string $month, User $by): PayrollRun
    {
        if (! $by->hasPerm('payroll.manage')) {
            throw new BizException('Only HR can run payroll.');
        }
        $existing = PayrollRun::where('month', $month)->first();
        if ($existing && $existing->status !== 'DRAFT') {
            throw new BizException('Payroll for '.$month.' is already '.strtolower($existing->status).'.');
        }
        $start = Carbon::parse($month.'-01');
        if ($start->isFuture()) {
            throw new BizException('Cannot run payroll for a future month.');
        }

        return DB::transaction(function () use ($month, $by, $existing, $start) {
            $existing?->delete();
            $run = PayrollRun::create(['month' => $month, 'created_by' => $by->id]);
            $total = 0;
            foreach (User::where('status', 'ACTIVE')->where('basic_salary', '>', 0)->get() as $u) {
                $total += static::slip($run, $u, $start)->net;
            }
            $run->update(['total_net' => round($total, 2)]);

            return $run->fresh();
        });
    }

    protected static function slip(PayrollRun $run, User $u, Carbon $start): Payslip
    {
        $end = $start->copy()->endOfMonth();
        $until = $end->isFuture() ? today() : $end;
        $wd = (int) Features::setting('payroll.working_days', 26);
        $dayRate = $u->basic_salary / max(1, $wd);
        $hourly = $dayRate / 8;

        $rosters = Roster::where('user_id', $u->id)->whereBetween('date', [$start->toDateString(), $until->toDateString()])->where('is_off', false)->whereNotNull('shift_template_id')->get();
        $atts = Attendance::where('user_id', $u->id)->whereBetween('date', [$start->toDateString(), $until->toDateString()])->whereNotNull('check_in_at')->get()->keyBy(fn ($a) => $a->date->toDateString());
        $holidays = Holiday::whereBetween('date', [$start->toDateString(), $until->toDateString()])->pluck('date')->map(fn ($d) => Carbon::parse($d)->toDateString())->all();
        $absent = 0;
        $leave = 0;
        foreach ($rosters as $r) {
            $d = $r->date->toDateString();
            if (isset($atts[$d]) || in_array($d, $holidays)) {
                continue;
            }
            if (AttendanceService::onLeave($u->id, $d)) {
                $leave++;
            } elseif ($r->date->lt(today())) {
                $absent++;
            }
        }
        $late = $atts->where('status', 'LATE')->count();
        $per = max(1, (int) Features::setting('attendance.lates_per_deduction', 3));
        $lateDed = round(intdiv($late, $per) * $dayRate, 2);
        $absentDed = round($absent * $dayRate, 2);
        $otHours = round($atts->where('ot_status', 'APPROVED')->sum('ot_minutes') / 60, 2);
        $otAmt = round($otHours * $hourly * (float) Features::setting('payroll.ot_multiplier', 1.5), 2);

        $kpiScore = null;
        $kpiAmt = 0;
        if (Features::enabled('kpi.payroll_link', null, true)) {
            $kpiScore = KpiService::score($u, $start->format('Y-m'))['score'];
            $kpiAmt = KpiService::incentive($kpiScore);
        }
        $advDed = 0;
        foreach (SalaryAdvance::where('user_id', $u->id)->where('status', 'PAID')->where('remaining', '>', 0)->get() as $adv) {
            $advDed += min($adv->installment, $adv->remaining);
        }
        $gross = round($u->basic_salary + $u->house_rent + $u->medical + $u->conveyance + $otAmt + $kpiAmt, 2);
        $ded = round($lateDed + $absentDed + $advDed, 2);

        return Payslip::create([
            'payroll_run_id' => $run->id, 'user_id' => $u->id, 'location_id' => $u->location_id,
            'basic' => $u->basic_salary, 'house_rent' => $u->house_rent, 'medical' => $u->medical, 'conveyance' => $u->conveyance,
            'rostered_days' => $rosters->count(), 'present_days' => $atts->count(), 'absent_days' => $absent, 'leave_days' => $leave,
            'late_count' => $late, 'ot_hours' => $otHours, 'ot_amount' => $otAmt, 'kpi_score' => $kpiScore, 'kpi_incentive' => $kpiAmt,
            'late_deduction' => $lateDed, 'absent_deduction' => $absentDed, 'advance_deduction' => round($advDed, 2),
            'gross' => $gross, 'total_deduction' => $ded, 'net' => round(max(0, $gross - $ded), 2), 'payout_method' => $u->payout_method,
        ]);
    }

    public static function submit(PayrollRun $run, User $by): void
    {
        if (! $by->hasPerm('payroll.manage')) {
            throw new BizException('Only HR can submit payroll.');
        }
        if ($run->status !== 'DRAFT') {
            throw new BizException('Already submitted.');
        }
        $run->update(['status' => 'PENDING_APPROVAL']);
        ApprovalService::start($run, $by);
    }

    public static function pay(PayrollRun $run, User $by): PayrollRun
    {
        if (! $by->hasPerm('acc.manage') && ! $by->hasPerm('payroll.manage')) {
            throw new BizException('Not allowed.');
        }
        if ($run->status !== 'APPROVED') {
            throw new BizException('Payroll must be approved before paying.');
        }
        $acc = fn (string $type) => MoneyAccount::where('type', $type)->where('active', true)->orderBy('id')->first()
            ?? throw new BizException("No $type money account for salary payment.");
        DB::transaction(function () use ($run, $acc) {
            $lines = [];
            $byLoc = [];
            $byMoney = [];
            foreach ($run->payslips()->with('user')->get() as $p) {
                $expense = $p->gross - $p->late_deduction - $p->absent_deduction;
                $byLoc[$p->location_id] = ($byLoc[$p->location_id] ?? 0) + $expense;
                if ($p->advance_deduction > 0) {
                    $lines[] = ['code' => '1300', 'credit' => $p->advance_deduction, 'user_id' => $p->user_id, 'memo' => 'Advance recovery'];
                    $left = $p->advance_deduction;
                    foreach (SalaryAdvance::where('user_id', $p->user_id)->where('status', 'PAID')->where('remaining', '>', 0)->get() as $adv) {
                        $take = min($left, $adv->remaining, $adv->installment);
                        $adv->update(['remaining' => round($adv->remaining - $take, 2), 'status' => $adv->remaining - $take <= 0.01 ? 'CLOSED' : 'PAID']);
                        $left -= $take;
                    }
                }
                $ma = $acc(['BANK' => 'BANK', 'MFS' => 'MFS'][$p->payout_method] ?? 'CASH');
                $byMoney[$ma->id] = ($byMoney[$ma->id] ?? 0) + $p->net;
                $p->update(['paid_at' => now()]);
                Notifier::send($p->user_id, 'Payslip ready: '.$run->month, 'Net pay ৳'.number_format($p->net).' via '.$p->payout_method, 'success', '/payslips');
            }
            foreach ($byLoc as $loc => $amt) {
                $lines[] = ['code' => '6000', 'debit' => round($amt, 2), 'location_id' => $loc];
            }
            foreach ($byMoney as $maId => $amt) {
                LedgerService::ensureFunds(MoneyAccount::find($maId), $amt);
                $lines[] = LedgerService::moneyLine(MoneyAccount::find($maId), 0, round($amt, 2), 'Salaries '.$run->month);
            }
            LedgerService::post('Payroll '.$run->month, $lines, $run);
            $run->update(['status' => 'PAID', 'paid_at' => now()]);
        });

        return $run->fresh();
    }

    public static function requestAdvance(User $u, float $amount, int $months, ?string $reason): SalaryAdvance
    {
        if (! $u->hasPerm('payroll.advance_request')) {
            throw new BizException('Salary advance is not enabled.');
        }
        // rules from Company settings → Salary advance
        $maxPct = max(1, (float) Features::setting('advance.max_percent', 100));
        $maxAmount = round($u->basic_salary * $maxPct / 100, 2);
        if ($amount <= 0 || $amount > $maxAmount) {
            throw new BizException('Advance must be between ৳1 and ৳'.number_format($maxAmount).' ('.$maxPct.'% of your basic salary).');
        }
        $maxMonths = max(1, (int) Features::setting('advance.max_months', 6));
        if ($months > $maxMonths) {
            throw new BizException('It can be paid back over at most '.$maxMonths.' month(s).');
        }
        $minService = (int) Features::setting('advance.min_service_months', 0);
        if ($minService > 0 && $u->joined_at && $u->joined_at->gt(now()->subMonths($minService))) {
            throw new BizException('Salary advance is available after '.$minService.' months of service.');
        }
        if ((int) Features::setting('advance.one_open_only', 1) && SalaryAdvance::where('user_id', $u->id)->whereIn('status', ['PENDING', 'PENDING_APPROVAL', 'APPROVED', 'PAID'])->where('remaining', '>', 0)->exists()) {
            throw new BizException('You already have an advance that is not fully paid back.');
        }
        $a = SalaryAdvance::create(['user_id' => $u->id, 'amount' => $amount, 'installment' => round($amount / max(1, $months), 2), 'remaining' => $amount, 'reason' => $reason]);
        ApprovalService::start($a, $u);

        return $a;
    }

    public static function payAdvance(SalaryAdvance $a, User $by, int $moneyAccountId): SalaryAdvance
    {
        if (! $by->hasPerm('acc.manage')) {
            throw new BizException('Only Accounts can pay advances.');
        }
        if ($a->status !== 'APPROVED') {
            throw new BizException('Advance is not approved.');
        }
        $ma = MoneyAccount::findOrFail($moneyAccountId);
        LedgerService::ensureFunds($ma, $a->amount);
        LedgerService::post('Salary advance to '.$a->user->name, [['code' => '1300', 'debit' => $a->amount, 'user_id' => $a->user_id], LedgerService::moneyLine($ma, 0, $a->amount)], $a);
        $a->update(['status' => 'PAID', 'remaining' => $a->amount]);
        Notifier::send($a->user_id, 'Salary advance paid ৳'.number_format($a->amount), 'Recovered ৳'.number_format($a->installment).'/month', 'success');

        return $a;
    }
}
