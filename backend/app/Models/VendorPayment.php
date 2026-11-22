<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VendorPayment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'amount' => 'float',
        ];
    }

    public function bill()
    {
        return $this->belongsTo(VendorBill::class, 'vendor_bill_id');
    }

    public function moneyAccount()
    {
        return $this->belongsTo(MoneyAccount::class);
    }
}
