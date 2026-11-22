<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseAssignment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'deadline_at' => 'datetime',
            'accepted_at' => 'datetime',
            'bought_at' => 'datetime',
            'delivered_at' => 'datetime',
            'price_flag' => 'boolean',
            'actual_amount' => 'float',
        ];
    }

    public function requisition()
    {
        return $this->belongsTo(Requisition::class);
    }

    public function buyer()
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function assigner()
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function paymentMethod()
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function pettyAccount()
    {
        return $this->belongsTo(MoneyAccount::class, 'petty_account_id');
    }

    public function lines()
    {
        return $this->hasMany(PurchaseAssignmentLine::class);
    }
}
