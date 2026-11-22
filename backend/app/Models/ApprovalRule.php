<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApprovalRule extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'levels' => 'array',
            'min_amount' => 'float',
            'max_amount' => 'float',
        ];
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }
}
