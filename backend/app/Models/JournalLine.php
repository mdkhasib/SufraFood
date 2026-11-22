<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JournalLine extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'debit' => 'float',
            'credit' => 'float',
        ];
    }

    public function entry()
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function moneyAccount()
    {
        return $this->belongsTo(MoneyAccount::class);
    }
}
