<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosOrderLine extends Model
{
    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'qty' => 'float',
            'price' => 'float',
            'total' => 'float',
        ];
    }

    public function order()
    {
        return $this->belongsTo(PosOrder::class, 'pos_order_id');
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }
}
