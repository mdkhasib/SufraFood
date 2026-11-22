<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApprovalStep extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'acted_at' => 'datetime',
        ];
    }

    public function approvable()
    {
        return $this->morphTo();
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'acted_by');
    }

    public function approverUser()
    {
        return $this->belongsTo(User::class, 'approver_user_id');
    }
}
