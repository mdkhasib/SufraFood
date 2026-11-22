<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecipeLine extends Model
{
    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'qty' => 'float',
        ];
    }

    public function recipe()
    {
        return $this->belongsTo(Recipe::class);
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }
}
