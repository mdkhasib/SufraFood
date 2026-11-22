<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CateringEventItem extends Model
{
    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'qty' => 'float',
        ];
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function event()
    {
        return $this->belongsTo(CateringEvent::class, 'catering_event_id');
    }
}
