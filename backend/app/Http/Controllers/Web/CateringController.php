<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\CateringEvent;
use App\Models\Item;
use App\Models\MoneyAccount;
use App\Models\User;
use App\Services\CateringService;
use App\Services\Numbering;
use Illuminate\Http\Request;

class CateringController extends Controller
{
    public function index()
    {
        return view('cat.index', ['events' => CateringEvent::with('manager')->orderByRaw("status in ('CLOSED','CANCELLED')")->orderBy('event_at')->get()]);
    }

    public function create()
    {
        return view('cat.create', [
            'food' => Item::whereIn('type', ['SEMI'])->orderBy('name')->get(),
            'equip' => Item::whereIn('type', ['ASSET', 'CONSUMABLE'])->orderBy('name')->get(),
            'staff' => User::whereHas('location', fn ($q) => $q->where('type', 'CATERING'))->orWhereHas('role', fn ($q) => $q->where('key', 'driver'))->orderBy('name')->get(),
        ]);
    }

    public function store(Request $r)
    {
        $r->validate(['title' => 'required', 'client_name' => 'required', 'event_at' => 'required|date', 'venue' => 'required', 'guests' => 'required|integer|min:1', 'price_per_head' => 'required|numeric']);
        $e = CateringEvent::create($r->only('title', 'client_name', 'client_phone', 'event_at', 'venue', 'lat', 'lng', 'guests', 'package_name', 'price_per_head', 'notes') + [
            'number' => Numbering::next('CE', 'catering_events'), 'total' => $r->guests * $r->price_per_head, 'status' => 'INQUIRY', 'manager_id' => $r->user()->id, 'radius_m' => 100,
        ]);
        foreach (['food' => 'FOOD', 'equip' => 'EQUIPMENT'] as $key => $kind) {
            foreach ($r->input($key, []) as $row) {
                if (! empty($row['item_id']) && (float) ($row['qty'] ?? 0) > 0) {
                    $e->items()->create(['item_id' => $row['item_id'], 'qty' => $row['qty'], 'kind' => $kind]);
                }
            }
        }
        $e->staff()->sync(collect($r->input('staff', []))->mapWithKeys(fn ($id) => [$id => ['duty' => 'Service']])->all());

        return redirect()->route('cat.show', $e)->with('ok', 'Event saved as inquiry');
    }

    public function show(CateringEvent $event)
    {
        return view('cat.show', ['e' => $event->load('items.item', 'staff', 'payments.moneyAccount', 'requisitions', 'manager'), 'money' => MoneyAccount::where('active', true)->whereIn('type', ['CASH', 'BANK', 'MFS'])->get()]);
    }

    public function action(Request $r, CateringEvent $event, string $action)
    {
        $u = $r->user();
        match ($action) {
            'confirm' => CateringService::confirm($event, $u),
            'payment' => CateringService::recordPayment($event, $u, (int) $r->money_account_id, (float) $r->amount, $r->reference),
            'delivered', 'closed', 'cancelled' => CateringService::setStatus($event, $u, strtoupper($action)),
            default => abort(404),
        };

        return back()->with('ok', 'Event updated');
    }
}
