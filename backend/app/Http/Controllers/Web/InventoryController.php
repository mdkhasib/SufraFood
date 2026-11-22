<?php

namespace App\Http\Controllers\Web;

use App\Exceptions\BizException;
use App\Http\Controllers\Controller;
use App\Models\Dispatch;
use App\Models\Item;
use App\Models\Location;
use App\Models\ProductionOrder;
use App\Models\Recipe;
use App\Models\Stock;
use App\Models\StockCount;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\LedgerService;
use App\Services\Notifier;
use App\Services\ProductionService;
use App\Services\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class InventoryController extends Controller
{
    protected function locationFor(Request $r): int
    {
        $u = $r->user();

        return $u->isGroupWide() && $r->filled('location_id') ? (int) $r->location_id : ($u->location_id ?? Location::value('id'));
    }

    public function stock(Request $r)
    {
        $loc = $this->locationFor($r);
        $stocks = Stock::with('item')->where('location_id', $loc)->get()
            ->filter(fn ($s) => ! $r->filled('q') || str_contains(strtolower($s->item->name.' '.$s->item->code), strtolower($r->q)))
            ->filter(fn ($s) => ! $r->filled('type') || $s->item->type === $r->type)
            ->sortBy(fn ($s) => $s->item->type.$s->item->name);
        $locations = $r->user()->isGroupWide() ? Location::orderBy('name')->get() : collect();
        $value = $stocks->sum(fn ($s) => $s->qty * $s->item->last_price);
        $items = Item::whereIn('type', ['RAW', 'SEMI', 'CONSUMABLE', 'ASSET'])->orderBy('name')->get();

        return view('inv.stock', compact('stocks', 'loc', 'locations', 'value', 'items'));
    }

    public function movements(Request $r)
    {
        $loc = $this->locationFor($r);
        $moves = StockMovement::with('item', 'user')->where('location_id', $loc)->when($r->filled('item_id'), fn ($q) => $q->where('item_id', $r->item_id))->latest('id')->paginate(60)->withQueryString();
        $locations = $r->user()->isGroupWide() ? Location::orderBy('name')->get() : collect();

        return view('inv.movements', compact('moves', 'loc', 'locations'));
    }

    public function count(Request $r)
    {
        $r->validate(['item_id' => 'required', 'counted_qty' => 'required|numeric|min:0']);
        $loc = $this->locationFor($r);
        $sys = StockService::qty($loc, (int) $r->item_id);
        $var = round($r->counted_qty - $sys, 3);
        StockCount::create(['location_id' => $loc, 'item_id' => $r->item_id, 'system_qty' => $sys, 'counted_qty' => $r->counted_qty, 'variance' => $var, 'user_id' => $r->user()->id]);
        if (abs($var) > 0.0005) {
            $mv = StockService::move($loc, (int) $r->item_id, $var, 'ADJUSTMENT', null, null, 'Stock count');
            $amt = round(abs($var) * $mv->unit_cost, 2);
            LedgerService::post('Stock count variance '.$mv->item->name, $var < 0
                ? [['code' => '5100', 'debit' => $amt, 'location_id' => $loc], ['code' => '1200', 'credit' => $amt, 'location_id' => $loc]]
                : [['code' => '1200', 'debit' => $amt, 'location_id' => $loc], ['code' => '5100', 'credit' => $amt, 'location_id' => $loc]], $mv, $loc);
            if ($var < 0) {
                Notifier::send(array_filter([Location::find($loc)?->manager_id]), 'Stock count variance', $mv->item->name.': '.$var.' '.$mv->item->unit.' (৳'.number_format($amt).')', 'warning');
            }
        }

        return back()->with('ok', 'Count saved · variance '.$var);
    }

    public function wastage(Request $r)
    {
        $r->validate(['item_id' => 'required', 'qty' => 'required|numeric|min:0.001', 'reason' => 'required']);
        $loc = $this->locationFor($r);
        $mv = StockService::move($loc, (int) $r->item_id, -(float) $r->qty, 'WASTAGE', null, null, $r->reason);
        $amt = round($r->qty * $mv->unit_cost, 2);
        LedgerService::post('Wastage '.$mv->item->name, [['code' => '5100', 'debit' => $amt, 'location_id' => $loc], ['code' => '1200', 'credit' => $amt, 'location_id' => $loc]], $mv, $loc);
        if ($amt >= 1000) {
            Notifier::send(array_filter([Location::find($loc)?->manager_id, User::whereHas('role', fn ($q) => $q->where('key', 'gm'))->value('id')]), 'Large wastage ৳'.number_format($amt), $mv->item->name.' '.$r->qty.' · '.$r->reason, 'warning');
        }

        return back()->with('ok', 'Wastage logged (৳'.number_format($amt).')');
    }

    public function recipes(Request $r)
    {
        $items = Item::with('recipe.lines.item')->whereIn('type', ['SEMI', 'MENU'])->orderBy('type')->orderBy('name')->get();
        $ingredients = Item::whereIn('type', ['RAW', 'SEMI'])->orderBy('name')->get();

        return view('inv.recipes', compact('items', 'ingredients'));
    }

    public function saveRecipe(Request $r, Item $item)
    {
        $rec = Recipe::firstOrCreate(['item_id' => $item->id], ['yield_qty' => 1]);
        $rec->update(['yield_qty' => max(0.001, (float) $r->input('yield_qty', 1))]);
        $rec->lines()->delete();
        foreach ($r->input('lines', []) as $l) {
            if (! empty($l['item_id']) && (float) ($l['qty'] ?? 0) > 0) {
                $rec->lines()->create(['item_id' => $l['item_id'], 'qty' => $l['qty']]);
            }
        }
        if ($item->type === 'SEMI' || $item->type === 'MENU') {
            $item->update(['last_price' => ProductionService::recipeCost($item)]);
        }

        return back()->with('ok', 'Recipe saved · cost ৳'.$item->fresh()->last_price.' per '.$item->unit);
    }

    public function production(Request $r)
    {
        $date = Carbon::parse($r->input('date', today()->toDateString()));
        $plan = ProductionService::plan($date);
        $orders = ProductionOrder::with('item', 'assignee')->whereDate('planned_for', $date)->orderBy('status')->get();
        $needs = ProductionService::rawNeeds($date);
        $cooks = User::where('location_id', $plan['ck']->id)->where('status', 'ACTIVE')->orderBy('name')->get();

        return view('inv.production', compact('date', 'plan', 'orders', 'needs', 'cooks'));
    }

    public function productionAction(Request $r, string $action)
    {
        $u = $r->user();
        $date = Carbon::parse($r->input('date', today()->toDateString()));
        $msg = match ($action) {
            'plan' => count(ProductionService::createOrders($date, $u, $r->input('assigned_to') ? (int) $r->assigned_to : null)).' production orders created',
            'raw' => 'Raw material request '.ProductionService::requestRawMaterial($date, $u)->number.' sent to warehouse',
            'start' => tap('Started', fn () => ProductionService::start(ProductionOrder::findOrFail($r->order_id), $u)),
            'complete' => tap('Done — stock updated', fn () => ProductionService::complete(ProductionOrder::findOrFail($r->order_id), $u, $r->filled('qty') ? (float) $r->qty : null)),
            default => throw new BizException('Unknown action'),
        };

        return back()->with('ok', $msg);
    }

    public function deliveries(Request $r)
    {
        $u = $r->user();
        $q = Dispatch::with('requisition.lines.item', 'fromLocation', 'toLocation', 'driver');
        if ($u->hasPerm('dispatch.drive') && ! $u->isGroupWide()) {
            $q->where('driver_id', $u->id);
        } elseif (! $u->isGroupWide()) {
            $q->where(fn ($w) => $w->where('from_location_id', $u->location_id)->orWhere('to_location_id', $u->location_id));
        }

        return view('inv.deliveries', ['items' => $q->latest('id')->paginate(40)]);
    }
}
