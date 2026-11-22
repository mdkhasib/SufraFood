<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CateringPayment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'amount' => 'float',
        ];
    }

    public function event()
    {
        return $this->belongsTo(CateringEvent::class, 'catering_event_id');
    }

    public function moneyAccount()
    {
        return $this->belongsTo(MoneyAccount::class);
    }
}
