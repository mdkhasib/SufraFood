<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RequisitionLine extends Model
{
    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'qty_requested' => 'float',
            'qty_approved' => 'float',
            'qty_sent' => 'float',
            'qty_received' => 'float',
            'est_price' => 'float',
        ];
    }

    public function requisition()
    {
        return $this->belongsTo(Requisition::class);
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }
}
