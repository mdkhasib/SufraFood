<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Location extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(fn (Location $l) => $l->qr_token ??= \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(12)));
    }

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'delivery_rate' => 'float',
            'lat' => 'float',
            'lng' => 'float',
        ];
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function manager()
    {
        return $this->belongsTo(User::class, 'manager_id');
    }
}
