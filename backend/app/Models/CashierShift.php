<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashierShift extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'opening_cash' => 'float',
            'expected_cash' => 'float',
            'counted_cash' => 'float',
            'difference' => 'float',
        ];
    }

    public function cashier()
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function orders()
    {
        return $this->hasMany(PosOrder::class);
    }
}
