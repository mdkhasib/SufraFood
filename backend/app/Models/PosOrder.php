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
            'rider_assigned_at' => 'datetime',
            'ready_at' => 'datetime',
            'picked_up_at' => 'datetime',
            'delivered_at' => 'datetime',
            'rider_fee' => 'float',
            'cash_collected' => 'float',
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

    public function rider()
    {
        return $this->belongsTo(User::class, 'rider_id');
    }

    public function riderAssigner()
    {
        return $this->belongsTo(User::class, 'rider_assigned_by');
    }

    public function isDelivery(): bool
    {
        return $this->order_type === 'DELIVERY' && $this->delivery_status !== null;
    }

    /** Amount the rider must collect from the customer (0 when already paid). */
    public function toCollect(): float
    {
        return $this->status === 'PAID' ? 0.0 : (float) $this->total;
    }

    public function mapsUrl(): ?string
    {
        $q = trim($this->delivery_address.' '.$this->delivery_area);

        return $q ? 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($q.', Dhaka') : null;
    }

    public function table()
    {
        return $this->belongsTo(DiningTable::class, 'dining_table_id');
    }
}
