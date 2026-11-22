<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionOrder extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'planned_for' => 'date',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'qty' => 'float',
        ];
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
