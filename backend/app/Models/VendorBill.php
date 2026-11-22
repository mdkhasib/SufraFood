<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VendorBill extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'bill_date' => 'date',
            'due_date' => 'date',
            'amount' => 'float',
            'paid_amount' => 'float',
        ];
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function payments()
    {
        return $this->hasMany(VendorPayment::class);
    }

    public function assignment()
    {
        return $this->belongsTo(PurchaseAssignment::class, 'purchase_assignment_id');
    }
}
