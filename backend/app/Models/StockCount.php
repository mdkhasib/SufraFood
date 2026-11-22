<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockCount extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'system_qty' => 'float',
            'counted_qty' => 'float',
            'variance' => 'float',
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

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
