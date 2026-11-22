<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollRun extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'paid_at' => 'datetime',
            'total_net' => 'float',
        ];
    }

    public function payslips()
    {
        return $this->hasMany(Payslip::class);
    }

    public function approvalSteps()
    {
        return $this->morphMany(ApprovalStep::class, 'approvable');
    }
}
