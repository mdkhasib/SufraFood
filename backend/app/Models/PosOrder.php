<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosOrder extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'paid_at' => 'datetime',
            'subtotal' => 'float',
            'discount' => 'float',
            'service_charge' => 'float',
            'vat' => 'float',
            'total' => 'float',
        ];
    }

    public function lines()
    {
        return $this->hasMany(PosOrderLine::class);
    }

    public function payments()
    {
        return $this->hasMany(PosPayment::class);
    }

    public function cashier()
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function table()
    {
        return $this->belongsTo(DiningTable::class, 'dining_table_id');
    }
}
