<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StandingOrder extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'qty' => 'float',
            'active' => 'boolean',
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
}
