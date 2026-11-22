<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JournalEntry extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }

    public function lines()
    {
        return $this->hasMany(JournalLine::class);
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function poster()
    {
        return $this->belongsTo(User::class, 'posted_by');
    }
}
