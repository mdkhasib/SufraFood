<?php

namespace App\Services;

use App\Exceptions\BizException;
use App\Models\Item;
use App\Models\Location;
use App\Models\ProductionOrder;
use App\Models\Recipe;
use App\Models\Requisition;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ProductionService
{
    public static function ck(): Location
    {
        return Location::where('type', 'CENTRAL_KITCHEN')->orderBy('id')->firstOrFail();
    }

    /** Demand for a date: accepted (and waiting) food orders to the CK, grouped by item. */
    public static function plan(Carbon $date): array
    {
        $ck = static::ck();
        $orders = Requisition::with('lines.item', 'fromLocation')->where('type', 'FOOD_ORDER')->where('to_location_id', $ck->id)
            ->whereDate('needed_by', $date)->whereNotIn('status', ['DRAFT', 'REJECTED', 'CANCELLED'])->get();
        $rows = [];
        foreach ($orders as $o) {
            foreach ($o->lines as $l) {
                if (! $l->item_id) {
                    continue;
                }
                $qty = $o->status === 'SENT_TO_FULFILLER' || $o->status === 'PENDING_APPROVAL' ? $l->qty_requested : ($l->qty_approved ?? $l->qty_requested);
                $rows[$l->item_id] ??= ['item' => $l->item, 'confirmed' => 0, 'waiting' => 0, 'sources' => [], 'planned' => 0, 'done' => 0];
                if (in_array($o->status, ['SENT_TO_FULFILLER', 'PENDING_APPROVAL'])) {
                    $rows[$l->item_id]['waiting'] += $qty;
                } else {
                    $rows[$l->item_id]['confirmed'] += $qty;
                }
                $rows[$l->item_id]['sources'][] = $o->fromLocation->name.' '.round($qty, 2).($o->source === 'CATERING' ? ' (catering)' : '').' · '.$o->number;
            }
        }
        foreach (ProductionOrder::where('location_id', $ck->id)->whereDate('planned_for', $date)->get() as $po) {
            $rows[$po->item_id] ??= ['item' => $po->item, 'confirmed' => 0, 'waiting' => 0, 'sources' => [], 'planned' => 0, 'done' => 0];
            $rows[$po->item_id]['planned'] += $po->qty;
            if ($po->status === 'DONE') {
                $rows[$po->item_id]['done'] += $po->qty;
            }
        }
        foreach ($rows as $id => &$r) {
            $r['stock'] = StockService::qty($ck->id, $id);
            $r['to_plan'] = max(0, round($r['confirmed'] - $r['planned'], 3));
        }

        return ['orders' => $orders, 'rows' => $rows, 'ck' => $ck];
    }

    public static function createOrders(Carbon $date, User $u, ?int $assignedTo = null): array
    {
        if (! $u->hasPerm('production.manage')) {
            throw new BizException('Only the CK head chef can plan production.');
        }
        $plan = static::plan($date);
        $created = [];
        foreach ($plan['rows'] as $itemId => $r) {
            if ($r['to_plan'] <= 0) {
                continue;
            }
            if (! Recipe::where('item_id', $itemId)->exists()) {
                throw new BizException('No recipe for '.$r['item']->name.'. Add the recipe first.');
            }
            $created[] = ProductionOrder::create([
                'number' => Numbering::next('PRD', 'production_orders'), 'location_id' => $plan['ck']->id, 'item_id' => $itemId,
                'qty' => $r['to_plan'], 'planned_for' => $date, 'assigned_to' => $assignedTo,
            ]);
        }
        if (! $created) {
            throw new BizException('Nothing new to plan — accept food orders first.');
        }
        if ($assignedTo) {
            Notifier::send($assignedTo, 'Production for '.$date->format('d M'), collect($created)->map(fn ($p) => $p->item->name.' '.$p->qty.$p->item->unit)->join(', '), 'task', '/production');
        }

        return $created;
    }

    /** Raw material needed for unfinished production orders of the date, minus CK stock. */
    public static function rawNeeds(Carbon $date): array
    {
        $ck = static::ck();
        $need = [];
        foreach (ProductionOrder::with('item.recipe.lines.item')->where('location_id', $ck->id)->whereDate('planned_for', $date)->where('status', '!=', 'DONE')->get() as $po) {
            $recipe = $po->item->recipe;
            if (! $recipe) {
                continue;
            }
            $factor = $po->qty / max(0.001, $recipe->yield_qty);
            foreach ($recipe->lines as $rl) {
                $need[$rl->item_id] = ($need[$rl->item_id] ?? 0) + $rl->qty * $factor;
            }
        }
        $out = [];
        foreach ($need as $itemId => $q) {
            $stock = StockService::qty($ck->id, $itemId);
            $out[$itemId] = ['item' => Item::find($itemId), 'need' => round($q, 3), 'stock' => $stock, 'request' => max(0, round($q - $stock, 3))];
        }

        return $out;
    }

    public static function requestRawMaterial(Carbon $date, User $u): Requisition
    {
        $needs = array_filter(static::rawNeeds($date), fn ($r) => $r['request'] > 0);
        if (! $needs) {
            throw new BizException('CK stock already covers the plan.');
        }
        $plan = static::plan($date);
        $parents = $plan['orders']->whereNotIn('status', ['SENT_TO_FULFILLER', 'PENDING_APPROVAL'])->pluck('id')->all();

        return RequisitionService::create($u, [
            'type' => 'RAW_MATERIAL', 'needed_by' => $date->copy()->setTime(6, 30),
            'lines' => array_map(fn ($r) => ['item_id' => $r['item']->id, 'qty' => $r['request']], array_values($needs)),
            'notes' => 'Raw material for production '.$date->format('d M'), 'parent_ids' => $parents,
        ], true, 'AUTO');
    }

    public static function start(ProductionOrder $po, User $u): ProductionOrder
    {
        if (! $u->hasPerm('production.work') && ! $u->hasPerm('production.manage')) {
            throw new BizException('Not allowed.');
        }
        if ($po->status !== 'PLANNED') {
            throw new BizException('Already started.');
        }
        $po->update(['status' => 'IN_PROGRESS', 'started_at' => now(), 'assigned_to' => $po->assigned_to ?? $u->id]);

        return $po;
    }

    /** Uses ingredients from CK stock, adds the finished item, updates its cost. */
    public static function complete(ProductionOrder $po, User $u, ?float $actualQty = null): ProductionOrder
    {
        if (! $u->hasPerm('production.work') && ! $u->hasPerm('production.manage')) {
            throw new BizException('Not allowed.');
        }
        if ($po->status === 'DONE') {
            throw new BizException('Already done.');
        }
        $recipe = $po->item->recipe()->with('lines.item')->first();
        if (! $recipe) {
            throw new BizException('No recipe for '.$po->item->name.'.');
        }
        $out = $actualQty ?? $po->qty;
        DB::transaction(function () use ($po, $recipe, $out) {
            $factor = $po->qty / max(0.001, $recipe->yield_qty);
            $cost = 0;
            foreach ($recipe->lines as $rl) {
                $q = round($rl->qty * $factor, 3);
                StockService::move($po->location_id, $rl->item_id, -$q, 'PRODUCTION_USE', $po, null, $po->number, false);
                $cost += $q * $rl->item->last_price;
            }
            $unitCost = $out > 0 ? round($cost / $out, 2) : 0;
            $po->item->update(['last_price' => $unitCost]);
            StockService::move($po->location_id, $po->item_id, $out, 'PRODUCTION_OUT', $po, $unitCost, $po->number);
            $po->update(['status' => 'DONE', 'completed_at' => now(), 'started_at' => $po->started_at ?? now(), 'qty' => $out]);
        });

        return $po->fresh();
    }

    public static function recipeCost(Item $item): float
    {
        $r = $item->recipe()->with('lines.item')->first();
        if (! $r) {
            return (float) $item->last_price;
        }

        return round($r->lines->sum(fn ($l) => $l->qty * $l->item->last_price) / max(0.001, $r->yield_qty), 2);
    }
}
