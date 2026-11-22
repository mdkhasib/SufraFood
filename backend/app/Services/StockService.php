<?php

namespace App\Services;

use App\Exceptions\BizException;
use App\Models\Item;
use App\Models\Stock;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Model;

class StockService
{
    public static function qty(int $locationId, int $itemId): float
    {
        return (float) (Stock::where('location_id', $locationId)->where('item_id', $itemId)->value('qty') ?? 0);
    }

    public static function move(int $locationId, int $itemId, float $qty, string $type, ?Model $ref = null, ?float $unitCost = null, ?string $reason = null, bool $allowNegative = true): StockMovement
    {
        if (abs($qty) < 0.0005) {
            throw new BizException('Quantity must not be zero.');
        }
        $stock = Stock::firstOrCreate(['location_id' => $locationId, 'item_id' => $itemId], ['qty' => 0]);
        if (! $allowNegative && $qty < 0 && $stock->qty + $qty < -0.0005) {
            $item = Item::find($itemId);
            throw new BizException('Not enough stock of '.$item?->name.' (available '.round($stock->qty, 3).' '.$item?->unit.').');
        }
        $stock->qty = round($stock->qty + $qty, 3);
        $stock->save();

        return StockMovement::create([
            'location_id' => $locationId, 'item_id' => $itemId, 'qty' => $qty, 'type' => $type,
            'unit_cost' => $unitCost ?? (float) Item::whereKey($itemId)->value('last_price'),
            'ref_type' => $ref ? class_basename($ref) : null, 'ref_id' => $ref?->getKey(),
            'reason' => $reason, 'user_id' => auth()->id(),
        ]);
    }

    /** Low stock items at a location. */
    public static function lowStock(int $locationId)
    {
        return Stock::with('item')->where('location_id', $locationId)->get()
            ->filter(fn ($s) => $s->item && $s->item->reorder_level > 0 && $s->qty < $s->item->reorder_level)->values();
    }
}
