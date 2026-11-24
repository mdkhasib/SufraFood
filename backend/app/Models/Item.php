<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * One table, two kinds of product that never mix:
 *  - MENU  = sale items (POS, delivery, menu) — have a sale price
 *  - RAW / SEMI / CONSUMABLE / ASSET = stock items (bought, cooked, used) — have a cost
 */
class Item extends Model
{
    public const SALE_TYPES = ['MENU'];

    public const STOCK_TYPES = ['RAW', 'SEMI', 'CONSUMABLE', 'ASSET'];

    /** Suggestions shown in the Category box (plus whatever categories are already in use). */
    public const MENU_CATEGORIES = ['Rice', 'Biryani & Kacchi', 'Curry', 'Grill', 'Bread', 'Snacks', 'Soup', 'Salad', 'Set menu', 'Platter', 'Dessert', 'Drinks', 'Juice', 'Tea & coffee'];

    public const STOCK_CATEGORIES = ['Rice & grains', 'Meat & poultry', 'Fish', 'Vegetables', 'Dairy & eggs', 'Spices', 'Oil & ghee', 'Grocery', 'Central kitchen', 'Packaging', 'Cleaning', 'Gas & fuel', 'Kitchen equipment', 'Furniture'];

    /** Real categories for the demo raw materials (they used to share one "Raw material" category). */
    public const RAW_CATEGORY_BY_CODE = [
        'RM-001' => 'Rice & grains', 'RM-013' => 'Rice & grains', 'RM-014' => 'Rice & grains',
        'RM-002' => 'Meat & poultry', 'RM-003' => 'Meat & poultry', 'RM-011' => 'Meat & poultry',
        'RM-004' => 'Vegetables', 'RM-012' => 'Vegetables', 'RM-015' => 'Vegetables', 'RM-018' => 'Vegetables', 'RM-019' => 'Vegetables',
        'RM-006' => 'Dairy & eggs', 'RM-010' => 'Dairy & eggs', 'RM-017' => 'Dairy & eggs',
        'RM-005' => 'Oil & ghee', 'RM-008' => 'Oil & ghee',
        'RM-007' => 'Spices', 'RM-009' => 'Spices', 'RM-016' => 'Grocery',
    ];

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

    public function isSaleItem(): bool
    {
        return in_array($this->type, self::SALE_TYPES);
    }

    public function imageUrl(): ?string
    {
        return $this->image ? Storage::disk('public')->url($this->image) : null;
    }

    /** Category suggestions for a kind of item: the usual list + categories already used. */
    public static function categorySuggestions(bool $sale): array
    {
        $used = static::whereIn('type', $sale ? self::SALE_TYPES : self::STOCK_TYPES)->whereNotNull('category')->distinct()->pluck('category')->all();

        return array_values(array_unique(array_merge($used, $sale ? self::MENU_CATEGORIES : self::STOCK_CATEGORIES)));
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
