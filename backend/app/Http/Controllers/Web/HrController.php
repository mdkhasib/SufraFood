<?php

namespace App\Http\Controllers\Web;

use App\Exceptions\BizException;
use App\Http\Controllers\Controller;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\MoneyAccount;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\SalaryAdvance;
use App\Services\ApprovalService;
use App\Services\KpiService;
use App\Services\PayrollService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class HrController extends Controller
{
    public function leave(Request $r)
    {
        $u = $r->user();
        $mine = LeaveRequest::with('leaveType')->where('user_id', $u->id)->latest('id')->get();
        $all = $u->hasPerm('leave.manage') ? LeaveRequest::with('leaveType', 'user.location')->latest('id')->limit(100)->get() : collect();
        $types = LeaveType::all()->map(function ($t) use ($u) {
            $t->used = (float) LeaveRequest::where('user_id', $u->id)->where('leave_type_id', $t->id)->where('status', 'APPROVED')->whereYear('from_date', now()->year)->sum('days');

            return $t;
        });

        return view('hr.leave', compact('mine', 'all', 'types'));
    }

    public function requestLeave(Request $r)
    {
        $r->validate(['leave_type_id' => 'required', 'from_date' => 'required|date', 'to_date' => 'required|date|after_or_equal:from_date']);
        $days = Carbon::parse($r->from_date)->diffInDays(Carbon::parse($r->to_date)) + 1;
        $type = LeaveType::findOrFail($r->leave_type_id);
        $used = (float) LeaveRequest::where('user_id', $r->user()->id)->where('leave_type_id', $type->id)->whereIn('status', ['APPROVED', 'PENDING'])->whereYear('from_date', now()->year)->sum('days');
        if ($type->days_per_year > 0 && $used + $days > $type->days_per_year) {
            throw new BizException('Not enough '.$type->name.' balance.');
        }
        $l = LeaveRequest::create(['user_id' => $r->user()->id, 'leave_type_id' => $type->id, 'from_date' => $r->from_date, 'to_date' => $r->to_date, 'days' => $days, 'reason' => $r->reason]);
        ApprovalService::start($l, $r->user());

        return back()->with('ok', 'Leave request sent for approval');
    }

    public function payroll()
    {
        return view('hr.payroll', ['runs' => PayrollRun::withCount('payslips')->latest('month')->get()]);
    }

    public function generate(Request $r)
    {
        $run = PayrollService::generate($r->input('month'), $r->user());

        return redirect()->route('payroll.run', $run)->with('ok', 'Payroll calculated for '.$run->payslips()->count().' employees');
    }

    public function run(PayrollRun $run)
    {
        $slips = $run->payslips()->with('user.location')->get()->sortBy(fn ($p) => $p->user->location_id.$p->user->name);
        $steps = ApprovalService::stepsFor($run);
        $money = MoneyAccount::where('active', true)->get();

        return view('hr.run', compact('run', 'slips', 'steps', 'money'));
    }

    public function runAction(Request $r, PayrollRun $run, string $action)
    {
        match ($action) {
            'submit' => PayrollService::submit($run, $r->user()),
            'pay' => PayrollService::pay($run, $r->user()),
            'recalculate' => PayrollService::generate($run->month, $r->user()),
            default => abort(404),
        };
        $run = PayrollRun::where('month', $run->month)->first();

        return redirect()->route('payroll.run', $run)->with('ok', ucfirst($action).' done');
    }

    public function payslips(Request $r)
    {
        return view('hr.payslips', ['slips' => Payslip::with('run')->where('user_id', $r->user()->id)->whereNotNull('paid_at')->latest('id')->get()]);
    }

    public function payslip(Request $r, Payslip $payslip)
    {
        $u = $r->user();
        abort_unless($payslip->user_id === $u->id || $u->hasPerm('payroll.manage') || $u->hasPerm('acc.manage'), 403);

        return view('hr.payslip', ['p' => $payslip->load('user.location', 'run')]);
    }

    public function advances(Request $r)
    {
        $u = $r->user();
        $mine = SalaryAdvance::where('user_id', $u->id)->latest('id')->get();
        $all = ($u->hasPerm('acc.manage') || $u->hasPerm('payroll.manage')) ? SalaryAdvance::with('user')->latest('id')->get() : collect();

        return view('hr.advances', ['mine' => $mine, 'all' => $all, 'money' => MoneyAccount::where('active', true)->whereIn('type', ['CASH', 'BANK', 'MFS'])->get()]);
    }

    public function requestAdvance(Request $r)
    {
        $r->validate(['amount' => 'required|numeric', 'months' => 'required|integer|min:1|max:12']);
        PayrollService::requestAdvance($r->user(), (float) $r->amount, (int) $r->months, $r->reason);

        return back()->with('ok', 'Request sent for approval');
    }

    public function payAdvance(Request $r, SalaryAdvance $advance)
    {
        PayrollService::payAdvance($advance, $r->user(), (int) $r->money_account_id);

        return back()->with('ok', 'Advance paid');
    }

    public function kpi(Request $r)
    {
        $u = $r->user();
        $month = $r->input('month', now()->format('Y-m'));
        $mine = $u->hasPerm('kpi.view_own') ? KpiService::score($u, $month) : null;
        $locId = $r->input('location_id', $u->isGroupWide() ? \App\Models\Location::where('code', 'BR-GUL')->value('id') : null);
        $team = $u->hasPerm('kpi.view_team') ? KpiService::team($u, $month, $locId ? (int) $locId : null) : [];
        $locations = $u->isGroupWide() ? \App\Models\Location::orderBy('name')->get() : collect();

        return view('hr.kpi', compact('mine', 'team', 'month', 'locations', 'locId'));
    }
}
