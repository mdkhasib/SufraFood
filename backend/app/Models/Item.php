<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Item extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'last_price' => 'float',
            'sale_price' => 'float',
            'reorder_level' => 'float',
            'buy_factor' => 'float',
        ];
    }

    public function recipe()
    {
        return $this->hasOne(Recipe::class);
    }

    public function stocks()
    {
        return $this->hasMany(Stock::class);
    }

    public function displayName(?string $lang = null): string
    {
        $lang = $lang ?? app()->getLocale();
        return ($lang === 'bn' && $this->name_bn) ? $this->name_bn : $this->name;
    }
}
