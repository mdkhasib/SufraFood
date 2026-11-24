<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payslip extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'paid_at' => 'datetime',
            'basic' => 'float',
            'house_rent' => 'float',
            'medical' => 'float',
            'conveyance' => 'float',
            'ot_amount' => 'float',
            'kpi_incentive' => 'float',
            'bonus' => 'float',
            'delivery_bonus' => 'float',
            'late_deduction' => 'float',
            'absent_deduction' => 'float',
            'advance_deduction' => 'float',
            'gross' => 'float',
            'total_deduction' => 'float',
            'net' => 'float',
            'ot_hours' => 'float',
            'absent_days' => 'float',
            'leave_days' => 'float',
        ];
    }

    public function run()
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
