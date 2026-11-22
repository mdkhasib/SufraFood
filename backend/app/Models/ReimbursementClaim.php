<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReimbursementClaim extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'paid_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function assignment()
    {
        return $this->belongsTo(PurchaseAssignment::class, 'purchase_assignment_id');
    }

    public function paidFrom()
    {
        return $this->belongsTo(MoneyAccount::class, 'paid_from');
    }

    public function approvalSteps()
    {
        return $this->morphMany(ApprovalStep::class, 'approvable');
    }
}
