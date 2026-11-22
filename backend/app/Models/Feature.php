<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Feature extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'depends_on' => 'array',
        ];
    }

    public function locations()
    {
        return $this->belongsToMany(Location::class, 'feature_locations');
    }
}
