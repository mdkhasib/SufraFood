<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaskTemplate extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'proof_required' => 'boolean',
            'active' => 'boolean',
            'last_generated_for' => 'date',
        ];
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function teamLead()
    {
        return $this->belongsTo(User::class, 'team_lead_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
