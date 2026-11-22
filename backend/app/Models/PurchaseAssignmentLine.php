<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseAssignmentLine extends Model
{
    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'qty' => 'float',
            'unit_price' => 'float',
        ];
    }

    public function assignment()
    {
        return $this->belongsTo(PurchaseAssignment::class, 'purchase_assignment_id');
    }

    public function requisitionLine()
    {
        return $this->belongsTo(RequisitionLine::class);
    }
}
