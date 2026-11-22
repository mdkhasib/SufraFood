<?php

namespace App\Services;

use App\Exceptions\BizException;
use App\Models\ApprovalRule;
use App\Models\ApprovalStep;
use App\Models\AttendanceCorrection;
use App\Models\Delegation;
use App\Models\LeaveRequest;
use App\Models\Location;
use App\Models\PayrollRun;
use App\Models\ReimbursementClaim;
use App\Models\Requisition;
use App\Models\SalaryAdvance;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ApprovalService
{
    public const TYPES = [
        'FOOD_ORDER', 'RAW_MATERIAL', 'SUPPLIES', 'EQUIPMENT', 'REPAIR', 'PURCHASE', 'TRANSFER',
        'LEAVE', 'ATTENDANCE_CORRECTION', 'REIMBURSEMENT', 'SALARY_ADVANCE', 'PAYROLL',
    ];

    public static function typeOf(Model $m): string
    {
        return match (true) {
            $m instanceof Requisition => $m->type,
            $m instanceof LeaveRequest => 'LEAVE',
            $m instanceof AttendanceCorrection => 'ATTENDANCE_CORRECTION',
            $m instanceof ReimbursementClaim => 'REIMBURSEMENT',
            $m instanceof SalaryAdvance => 'SALARY_ADVANCE',
            $m instanceof PayrollRun => 'PAYROLL',
            default => 'OTHER',
        };
    }

    public static function amountOf(Model $m): float
    {
        return (float) match (true) {
            $m instanceof Requisition => $m->estimated_amount,
            $m instanceof ReimbursementClaim, $m instanceof SalaryAdvance => $m->amount,
            $m instanceof PayrollRun => $m->total_net,
            default => 0,
        };
    }

    public static function titleOf(Model $m): string
    {
        return match (true) {
            $m instanceof Requisition => $m->typeLabel().' '.$m->number.' · '.$m->fromLocation?->name,
            $m instanceof LeaveRequest => 'Leave: '.$m->user?->name.' ('.$m->from_date->format('d M').' – '.$m->to_date->format('d M').')',
            $m instanceof AttendanceCorrection => 'Attendance correction: '.$m->user?->name.' '.$m->date->format('d M'),
            $m instanceof ReimbursementClaim => 'Refund claim: '.$m->user?->name.' ৳'.number_format($m->amount),
            $m instanceof SalaryAdvance => 'Salary advance: '.$m->user?->name.' ৳'.number_format($m->amount),
            $m instanceof PayrollRun => 'Payroll run '.$m->month.' ৳'.number_format($m->total_net),
            default => class_basename($m),
        };
    }

    public static function locationOf(Model $m): ?int
    {
        return match (true) {
            $m instanceof Requisition => $m->from_location_id,
            $m instanceof PayrollRun => null,
            default => $m->user?->location_id,
        };
    }

    /** Start the approval chain. Returns true when auto-approved immediately. */
    public static function start(Model $m, User $requester): bool
    {
        $type = static::typeOf($m);
        $amount = static::amountOf($m);
        $loc = static::locationOf($m);

        $rule = ApprovalRule::where('request_type', $type)
            ->where(fn ($q) => $q->whereNull('location_id')->orWhere('location_id', $loc))
            ->where('min_amount', '<=', $amount)
            ->where(fn ($q) => $q->whereNull('max_amount')->orWhere('max_amount', '>=', $amount))
            ->orderByRaw('location_id is null')
            ->orderByDesc('min_amount')
            ->first();

        $levels = $rule?->levels ?? [];
        $level = 0;
        $firstPending = null;
        foreach ($levels as $lv) {
            $level++;
            $by = $lv['by'] ?? 'role';
            $value = $lv['value'] ?? null;
            $userId = match ($by) {
                'user' => (int) $value,
                'direct_manager' => $requester->manager_id,
                'location_manager' => $loc ? Location::find($loc)?->manager_id : null,
                default => null,
            };
            $skip = ($userId && $userId === $requester->id)
                || ($by === 'role' && $requester->role?->key === $value)
                || (in_array($by, ['direct_manager', 'location_manager']) && ! $userId);
            $step = ApprovalStep::create([
                'approvable_type' => $m->getMorphClass(), 'approvable_id' => $m->getKey(),
                'level' => $level, 'approver_by' => $by, 'approver_value' => $value,
                'approver_user_id' => $userId, 'status' => $skip ? 'SKIPPED' : 'WAITING',
            ]);
            if (! $skip && ! $firstPending) {
                $firstPending = $step;
            }
        }
        if (! $firstPending) {
            static::finalize($m, true, $requester, null);
            return true;
        }
        static::activate($firstPending, $rule?->sla_minutes);
        return false;
    }

    protected static function activate(ApprovalStep $step, ?int $sla = null): void
    {
        $step->update(['status' => 'PENDING', 'due_at' => $sla ? now()->addMinutes($sla) : null]);
        $m = $step->approvable;
        Notifier::send(static::approversFor($step), 'Approval needed', static::titleOf($m), 'approval', '/approvals');
    }

    /** Users who may act on a pending step. */
    public static function approversFor(ApprovalStep $step): Collection
    {
        if ($step->approver_user_id) {
            $ids = [$step->approver_user_id];
            $ids = array_merge($ids, Delegation::where('from_user_id', $step->approver_user_id)
                ->whereDate('from_date', '<=', today())->whereDate('to_date', '>=', today())->pluck('to_user_id')->all());
            return User::whereIn('id', $ids)->get();
        }
        if ($step->approver_by === 'role') {
            $users = User::whereHas('role', fn ($q) => $q->where('key', $step->approver_value))->where('status', 'ACTIVE')->get();
            $delegates = Delegation::whereIn('from_user_id', $users->pluck('id'))
                ->whereDate('from_date', '<=', today())->whereDate('to_date', '>=', today())->pluck('to_user_id');
            return $users->merge(User::whereIn('id', $delegates)->get());
        }
        return collect();
    }

    public static function canAct(ApprovalStep $step, User $user): bool
    {
        if ($step->status !== 'PENDING') return false;
        if ($user->is_super_admin) return true;
        // whoever the approval rule names (a person, the direct/location manager, or a role holder) may act
        return static::approversFor($step)->contains('id', $user->id);
    }

    /** Pending steps the user can act on. */
    public static function inbox(User $user): Collection
    {
        return ApprovalStep::where('status', 'PENDING')->with('approvable')->orderBy('created_at')->get()
            ->filter(fn ($s) => $s->approvable && static::canAct($s, $user))->values();
    }

    public static function act(ApprovalStep $step, User $user, bool $approve, ?string $comment = null): void
    {
        if (! static::canAct($step, $user)) {
            throw new BizException('You cannot act on this approval.');
        }
        $m = $step->approvable;
        $amount = static::amountOf($m);
        if ($approve && $user->approval_limit !== null && ! $user->is_super_admin && $amount > $user->approval_limit) {
            throw new BizException('Amount ৳'.number_format($amount).' is above your approval limit of ৳'.number_format($user->approval_limit).'.');
        }
        if (! $approve && ! $comment) {
            throw new BizException('Please give a reason for rejecting.');
        }
        DB::transaction(function () use ($step, $user, $approve, $comment, $m) {
            $onBehalf = ($step->approver_user_id && $step->approver_user_id !== $user->id) ? $step->approver_user_id : null;
            $step->update(['status' => $approve ? 'APPROVED' : 'REJECTED', 'acted_by' => $user->id, 'acted_at' => now(), 'comment' => $comment, 'on_behalf_of' => $onBehalf]);
            Audit::log($approve ? 'approval.approved' : 'approval.rejected', $m, null, ['level' => $step->level, 'comment' => $comment]);
            if (! $approve) {
                ApprovalStep::where('approvable_type', $step->approvable_type)->where('approvable_id', $step->approvable_id)
                    ->where('status', 'WAITING')->update(['status' => 'SKIPPED']);
                static::finalize($m, false, $user, $comment);
                return;
            }
            $next = ApprovalStep::where('approvable_type', $step->approvable_type)->where('approvable_id', $step->approvable_id)
                ->where('status', 'WAITING')->orderBy('level')->first();
            if ($next) {
                static::activate($next);
            } else {
                static::finalize($m, true, $user, $comment);
            }
        });
    }

    public static function finalize(Model $m, bool $approved, User $by, ?string $comment): void
    {
        match (true) {
            $m instanceof Requisition => $approved ? RequisitionService::onApproved($m, $by) : RequisitionService::onRejected($m, $by, $comment),
            $m instanceof AttendanceCorrection => AttendanceService::onCorrectionDecided($m, $approved, $by),
            $m instanceof LeaveRequest => static::simple($m, $approved, 'Leave request'),
            $m instanceof ReimbursementClaim => static::simple($m, $approved, 'Refund claim'),
            $m instanceof SalaryAdvance => static::simple($m, $approved, 'Salary advance'),
            $m instanceof PayrollRun => $m->update(['status' => $approved ? 'APPROVED' : 'DRAFT']),
            default => null,
        };
    }

    protected static function simple(Model $m, bool $approved, string $label): void
    {
        $m->update(['status' => $approved ? 'APPROVED' : 'REJECTED']);
        if ($m->user_id) {
            Notifier::send($m->user_id, $label.' '.($approved ? 'approved' : 'rejected'), static::titleOf($m), $approved ? 'success' : 'warning');
        }
    }

    /** Timeline of steps for display. */
    public static function stepsFor(Model $m): Collection
    {
        return ApprovalStep::where('approvable_type', $m->getMorphClass())->where('approvable_id', $m->getKey())
            ->with('actor')->orderBy('level')->get();
    }
}
