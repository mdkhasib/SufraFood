<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'system' => 'boolean',
        ];
    }

    public function lines()
    {
        return $this->hasMany(JournalLine::class);
    }

    public function balance(?int $locationId = null, $from = null, $to = null): float
    {
        $q = JournalLine::query()->where('account_id', $this->id);
        if ($locationId) $q->where('journal_lines.location_id', $locationId);
        if ($from || $to) {
            $q->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id');
            if ($from) $q->whereDate('journal_entries.date', '>=', $from);
            if ($to) $q->whereDate('journal_entries.date', '<=', $to);
        }
        $dr = (float) (clone $q)->sum('debit');
        $cr = (float) (clone $q)->sum('credit');
        return in_array($this->type, ['ASSET', 'EXPENSE']) ? $dr - $cr : $cr - $dr;
    }
}
