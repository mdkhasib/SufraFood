<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\CashierShift;
use App\Models\DiningTable;
use App\Models\Item;
use App\Models\Location;
use App\Models\PaymentMethod;
use App\Models\PosOrder;
use App\Models\PosOrderLine;
use App\Services\PosService;
use App\Support\Features;
use Illuminate\Http\Request;

class PosController extends Controller
{
    public function index(Request $r)
    {
        $u = $r->user();
        $shift = PosService::currentShift($u);
        $menu = Item::where('type', 'MENU')->where('active', true)->orderBy('category')->orderBy('name')->get(['id', 'code', 'name', 'name_bn', 'category', 'sale_price']);
        $tables = DiningTable::where('location_id', $shift?->location_id ?? $u->location_id)->get(['id', 'name', 'seats']);
        $methods = PaymentMethod::where('active', true)->get(['id', 'name', 'type']);
        $open = $shift ? PosOrder::with('lines.item', 'table')->where('cashier_shift_id', $shift->id)->where('status', 'OPEN')->get() : collect();
        $branches = $u->isGroupWide() ? Location::where('type', 'BRANCH')->get() : collect();
        $cfg = ['vat' => (float) Features::setting('pos.vat_percent', 5), 'sc' => (float) Features::setting('pos.service_charge_percent', 0), 'discount_limit' => $u->is_super_admin ? 100 : (float) ($u->discount_limit ?? 0)];

        return view('pos.index', compact('shift', 'menu', 'tables', 'methods', 'open', 'cfg', 'branches'));
    }

    public function open(Request $r)
    {
        PosService::openShift($r->user(), (float) $r->input('opening_cash', 0), $r->input('location_id') ? (int) $r->location_id : null);

        return back()->with('ok', 'Shift opened');
    }

    public function close(Request $r)
    {
        $s = PosService::closeShift(PosService::currentShift($r->user()) ?? abort(404), $r->user(), (float) $r->counted_cash);

        return back()->with($s->difference == 0 ? 'ok' : 'error', 'Shift closed · expected ৳'.number_format($s->expected_cash).' counted ৳'.number_format($s->counted_cash).' · difference ৳'.number_format($s->difference, 2));
    }

    public function store(Request $r)
    {
        $o = PosService::createOrder($r->user(), $r->only('order_type', 'dining_table_id', 'customer_phone', 'discount', 'lines'));

        return response()->json(['message' => 'Order '.$o->number.' sent to kitchen', 'order' => $o]);
    }

    public function pay(Request $r, PosOrder $order)
    {
        $o = PosService::pay($order, $r->user(), $r->input('payments', []));

        return response()->json(['message' => 'Paid '.$o->number, 'order' => $o, 'receipt' => route('pos.receipt', $o)]);
    }

    public function void(Request $r, PosOrder $order)
    {
        PosService::void($order, $r->user(), (string) $r->input('reason'));

        return $r->expectsJson() ? response()->json(['message' => 'Voided']) : back()->with('ok', 'Order voided');
    }

    public function orders(Request $r)
    {
        $u = $r->user();
        $q = PosOrder::with('cashier', 'location', 'payments.paymentMethod', 'lines.item')->when(! $u->isGroupWide(), fn ($w) => $w->where('location_id', $u->location_id))->latest('id');

        return view('pos.orders', ['orders' => $q->paginate(40), 'shifts' => CashierShift::with('cashier', 'location')->when(! $u->isGroupWide(), fn ($w) => $w->where('location_id', $u->location_id))->latest('id')->limit(15)->get()]);
    }

    public function receipt(Request $r, PosOrder $order)
    {
        $u = $r->user();
        abort_unless($u->isGroupWide() || $order->location_id === $u->location_id || $order->cashier_id === $u->id, 403);

        return view('pos.receipt', ['o' => $order->load('lines.item', 'payments.paymentMethod', 'location', 'cashier', 'table'), 'tendered' => (float) $r->query('tendered', 0)]);
    }

    public function kds(Request $r)
    {
        return view('pos.kds');
    }

    public function kdsFeed(Request $r)
    {
        $loc = $r->user()->location_id;

        return PosOrder::with(['lines' => fn ($q) => $q->where('kot_status', 'NEW')->with('item'), 'table'])->where('location_id', $loc)->whereIn('status', ['OPEN', 'PAID'])
            ->where('created_at', '>=', now()->subHours(6))->whereHas('lines', fn ($q) => $q->where('kot_status', 'NEW'))->orderBy('id')->get()
            ->map(fn ($o) => ['id' => $o->id, 'number' => $o->number, 'type' => $o->order_type, 'table' => $o->table?->name, 'age' => $o->created_at->diffInMinutes(now()), 'at' => $o->created_at->format('h:i A'),
                'lines' => $o->lines->map(fn ($l) => ['id' => $l->id, 'name' => $l->item->name, 'name_bn' => $l->item->name_bn, 'qty' => (float) $l->qty, 'note' => $l->note])]);
    }

    public function ready(PosOrderLine $line)
    {
        $line->update(['kot_status' => 'READY']);

        return ['ok' => true];
    }

    public function summary(Request $r)
    {
        $u = $r->user();
        $date = $r->input('date', today()->toDateString());
        $locs = $u->isGroupWide() ? Location::where('type', 'BRANCH')->get() : Location::where('id', $u->location_id)->get();
        $rows = $locs->map(fn ($l) => ['location' => $l, 's' => PosService::daySummary($l->id, $date)]);

        return view('pos.summary', compact('rows', 'date'));
    }
}
