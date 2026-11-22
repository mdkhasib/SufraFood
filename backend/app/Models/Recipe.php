<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Recipe extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'yield_qty' => 'float',
        ];
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function lines()
    {
        return $this->hasMany(RecipeLine::class);
    }
}
