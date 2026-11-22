<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CateringEvent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'event_at' => 'datetime',
            'total' => 'float',
            'paid' => 'float',
            'price_per_head' => 'float',
            'lat' => 'float',
            'lng' => 'float',
        ];
    }

    public function items()
    {
        return $this->hasMany(CateringEventItem::class);
    }

    public function staff()
    {
        return $this->belongsToMany(User::class, 'catering_event_staff');
    }

    public function payments()
    {
        return $this->hasMany(CateringPayment::class);
    }

    public function manager()
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function requisitions()
    {
        return $this->hasMany(Requisition::class);
    }
}
