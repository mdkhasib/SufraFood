<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FeatureModule extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['admin_access' => 'boolean'];
    }

    public function features()
    {
        return $this->hasMany(Feature::class, 'module', 'name');
    }
}
