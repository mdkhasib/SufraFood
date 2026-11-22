<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalaryAdvance extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'installment' => 'float',
            'remaining' => 'float',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function approvalSteps()
    {
        return $this->morphMany(ApprovalStep::class, 'approvable');
    }
}
