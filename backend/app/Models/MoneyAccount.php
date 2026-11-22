<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MoneyAccount extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function paymentMethod()
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function custodian()
    {
        return $this->belongsTo(User::class, 'custodian_id');
    }

    public function balance(): float
    {
        $dr = (float) JournalLine::where('money_account_id', $this->id)->sum('debit');
        $cr = (float) JournalLine::where('money_account_id', $this->id)->sum('credit');
        return $dr - $cr;
    }
}
