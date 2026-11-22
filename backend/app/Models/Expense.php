<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'amount' => 'float',
        ];
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function moneyAccount()
    {
        return $this->belongsTo(MoneyAccount::class);
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }
}
