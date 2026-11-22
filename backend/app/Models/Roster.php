<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Roster extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'is_off' => 'boolean',
            'no_show_notified' => 'boolean',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function shift()
    {
        return $this->belongsTo(ShiftTemplate::class, 'shift_template_id');
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }
}
