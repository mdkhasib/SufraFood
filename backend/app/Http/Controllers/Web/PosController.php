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
        $menu = Item::where('type', 'MENU')->where('active', true)->orderBy('category')->orderBy('name')->get(['id', 'code', 'name', 'name_bn', 'category', 'sale_price', 'image'])
            ->map(fn ($i) => $i->only(['id', 'code', 'name', 'name_bn', 'category', 'sale_price']) + ['img' => $i->imageUrl()]);
        $tables = DiningTable::where('location_id', $shift?->location_id ?? $u->location_id)->get(['id', 'name', 'seats']);
        $methods = PaymentMethod::where('active', true)->get(['id', 'name', 'type']);
        $open = $shift ? PosOrder::with('lines.item', 'table')->where('cashier_shift_id', $shift->id)->where('status', 'OPEN')->where(fn ($w) => $w->whereNull('delivery_status')->orWhereIn('delivery_status', ['COOKING', 'READY']))->get() : collect();
        $branches = $u->isGroupWide() ? Location::where('type', 'BRANCH')->get() : collect();
        $cfg = ['vat' => (float) Features::setting('pos.vat_percent', 5), 'sc' => (float) Features::setting('pos.service_charge_percent', 0), 'discount_limit' => $u->is_super_admin ? 100 : (float) ($u->discount_limit ?? 0), 'delivery' => \App\Support\Delivery::on(),
            'offline' => (bool) (int) Features::setting('pos.offline_enabled', 1), 'sync_minutes' => max(1, (int) Features::setting('pos.sync_minutes', 10)),
            'user' => ['id' => $u->id, 'code' => $u->employee_code, 'name' => $u->name], 'location_id' => $shift?->location_id,
            'receipt' => $shift ? static::receiptCfg($shift->location) : null];
        $deliveriesOpen = $cfg['delivery'] ? PosOrder::where('location_id', $shift?->location_id ?? $u->location_id)->where(fn ($w) => $w->whereIn('delivery_status', ['COOKING', 'READY'])->whereNull('rider_id')->orWhere(fn ($x) => $x->where('delivery_status', 'DELIVERED')->where('status', 'OPEN')))->count() : 0;

        return view('pos.index', compact('shift', 'menu', 'tables', 'methods', 'open', 'cfg', 'branches', 'deliveriesOpen'));
    }

    /** Bill settings for printing offline bills in the browser (same layout as the server bill). */
    protected static function receiptCfg(?Location $loc): array
    {
        $S = fn ($k, $d = null) => Features::setting($k, $d);
        $logo = (int) $S('receipt.show_logo', 1) ? \App\Support\Brand::logoData() : null;

        return [
            'name' => $S('company.name'), 'tagline' => $S('company.tagline'), 'branch' => $loc?->name,
            'address' => (int) $S('receipt.show_address', 1) ? ($loc?->address ?: $S('company.address')) : null,
            'phone' => (int) $S('receipt.show_phone', 1) ? ($loc?->phone ?: $S('company.phone')) : null,
            'vat_reg' => (int) $S('receipt.show_vat_reg', 1) ? $S('company.vat_reg') : null,
            'title' => $S('receipt.title') ?: 'INVOICE', 'footer' => $S('receipt.footer'), 'footer2' => $S('receipt.footer2'),
            'paper' => (string) $S('receipt.paper', '80'), 'font' => max(8, min(20, (int) $S('receipt.font_size', 12))),
            'show_cashier' => (bool) (int) $S('receipt.show_cashier', 1), 'show_customer' => (bool) (int) $S('receipt.show_customer', 1),
            'logo' => $logo && strlen($logo) < 200000 ? $logo : null,
        ];
    }

    /** Is the server reachable (and am I still logged in)? Also hands out a fresh CSRF token for syncing. */
    public function ping(Request $r)
    {
        return ['ok' => true, 'csrf' => csrf_token(), 'user_id' => $r->user()->id, 'time' => now()->toIso8601String(),
            'offline' => (bool) (int) Features::setting('pos.offline_enabled', 1)];
    }

    /** Offline bills from this POS computer → real sales (idempotent by bill id). */
    public function sync(Request $r)
    {
        $res = \App\Services\PosSyncService::sync($r->user(), (array) $r->input('orders', []));
        $ok = count(array_filter($res, fn ($x) => $x['ok']));

        return ['message' => $ok.' of '.count($res).' offline bill(s) synced', 'results' => $res];
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
        $o = PosService::createOrder($r->user(), $r->only('order_type', 'dining_table_id', 'customer_phone', 'customer_name', 'delivery_address', 'delivery_area', 'delivery_note', 'discount', 'lines'));

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

        return PosOrder::with(['lines' => fn ($q) => $q->where('kot_status', 'NEW')->with('item'), 'table', 'rider'])->where('location_id', $loc)->whereIn('status', ['OPEN', 'PAID'])
            ->where('created_at', '>=', now()->subHours(6))->whereHas('lines', fn ($q) => $q->where('kot_status', 'NEW'))->orderBy('id')->get()
            ->map(fn ($o) => ['id' => $o->id, 'number' => $o->number, 'type' => $o->order_type, 'table' => $o->table?->name,
                'delivery' => $o->delivery_status ? trim(($o->customer_name ?: $o->customer_phone).' · '.($o->delivery_area ?: '')) : null, 'rider' => $o->rider?->name, 'online' => $o->source === 'ONLINE', 'age' => (int) $o->created_at->diffInMinutes(now()), 'at' => $o->created_at->format('h:i A'),
                'lines' => $o->lines->map(fn ($l) => ['id' => $l->id, 'name' => $l->item->name, 'name_bn' => $l->item->name_bn, 'qty' => (float) $l->qty, 'note' => $l->note])]);
    }

    public function ready(PosOrderLine $line)
    {
        $line->update(['kot_status' => 'READY']);
        \App\Services\DeliveryService::kitchenReady($line->order);

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
