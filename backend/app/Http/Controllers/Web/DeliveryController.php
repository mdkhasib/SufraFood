<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\Location;
use App\Models\PaymentMethod;
use App\Models\PosOrder;
use App\Services\DeliveryService;
use App\Support\Delivery;
use Illuminate\Http\Request;

/** Home delivery board (branch manager / cashier / online team), new order form and the riders' monthly report. */
class DeliveryController extends Controller
{
    protected function guard(Request $r): void
    {
        abort_unless(Delivery::on(), 403, 'Home delivery is switched off in Company settings.');
        abort_unless(Delivery::canView($r->user()), 403);
    }

    /** Branches this person works with: online team / group-wide = all, others = own branch. */
    protected function branches(Request $r)
    {
        $u = $r->user();

        return ($u->isGroupWide() || $u->hasPerm('delivery.order'))
            ? Location::where('type', 'BRANCH')->where('active', true)->orderBy('name')->get()
            : Location::where('id', $u->location_id)->where('type', 'BRANCH')->get();
    }

    public function index(Request $r)
    {
        $this->guard($r);
        $u = $r->user();
        $branches = $this->branches($r);
        $loc = $r->integer('location') ?: null;
        $ids = $loc && $branches->contains('id', $loc) ? [$loc] : $branches->pluck('id')->all();
        $view = $r->input('view', 'active');
        $q = PosOrder::with('lines.item', 'location', 'rider', 'cashier')->where('order_type', 'DELIVERY')->whereNotNull('delivery_status')->whereIn('location_id', $ids);
        match ($view) {
            'done' => $q->where(fn ($w) => $w->whereIn('delivery_status', ['DELIVERED', 'CANCELLED']))->where('updated_at', '>=', now()->subDays(2))->where(fn ($w) => $w->where('status', '!=', 'OPEN')->orWhere('delivery_status', 'CANCELLED')),
            'mine' => $q->where('cashier_id', $u->id)->where('created_at', '>=', now()->subDays(2)),
            default => $q->where(fn ($w) => $w->whereIn('delivery_status', ['COOKING', 'READY', 'PICKED_UP'])->orWhere(fn ($x) => $x->where('delivery_status', 'DELIVERED')->where('status', 'OPEN'))),
        };
        $orders = $q->orderBy('id', $view === 'active' ? 'asc' : 'desc')->limit(100)->get();
        $riders = collect($ids)->mapWithKeys(fn ($id) => [$id => Delivery::riders($id)]);
        $busy = PosOrder::whereIn('delivery_status', ['COOKING', 'READY', 'PICKED_UP'])->whereNotNull('rider_id')->selectRaw('rider_id, count(*) n')->groupBy('rider_id')->pluck('n', 'rider_id');
        $counts = PosOrder::where('order_type', 'DELIVERY')->whereIn('location_id', $ids)->whereNotNull('delivery_status')
            ->selectRaw('delivery_status, count(*) n')->where('created_at', '>=', today())->groupBy('delivery_status')->pluck('n', 'delivery_status');
        $cashWithRiders = PosOrder::whereIn('location_id', $ids)->where('delivery_status', 'DELIVERED')->where('status', 'OPEN')->sum('cash_collected');

        return view('delivery.index', compact('orders', 'branches', 'loc', 'view', 'riders', 'busy', 'counts', 'cashWithRiders'));
    }

    public function create(Request $r)
    {
        $this->guard($r);
        $u = $r->user();
        $branches = $this->branches($r);
        abort_if($branches->isEmpty(), 403, 'Delivery orders are taken for branches.');
        $menu = Item::where('type', 'MENU')->where('active', true)->orderBy('category')->orderBy('name')->get(['id', 'code', 'name', 'name_bn', 'category', 'sale_price']);
        $methods = PaymentMethod::where('active', true)->where('type', '!=', 'CASH')->get(['id', 'name', 'type']);
        $cfg = ['vat' => (float) \App\Support\Features::setting('pos.vat_percent', 5), 'sc' => (float) \App\Support\Features::setting('pos.service_charge_percent', 0), 'discount_limit' => $u->is_super_admin ? 100 : (float) ($u->discount_limit ?? 0)];
        $recent = PosOrder::where('order_type', 'DELIVERY')->whereNotNull('customer_phone')->latest('id')->limit(300)->get(['customer_phone', 'customer_name', 'delivery_address', 'delivery_area'])->unique('customer_phone')->values();

        return view('delivery.create', compact('branches', 'menu', 'methods', 'cfg', 'recent'));
    }

    public function store(Request $r)
    {
        $this->guard($r);
        $o = DeliveryService::place($r->user(), $r->only('location_id', 'customer_name', 'customer_phone', 'delivery_address', 'delivery_area', 'delivery_note', 'discount', 'lines', 'payment_mode', 'payment_method_id', 'reference'));

        return response()->json(['message' => 'Order '.$o->number.' sent to the '.$o->location->name.' kitchen', 'order' => $o, 'redirect' => route('delivery.index', ['view' => 'mine'])]);
    }

    public function action(Request $r, PosOrder $order, string $action)
    {
        $this->guard($r);
        $u = $r->user();
        $msg = match ($action) {
            'assign' => (DeliveryService::assign($order, $u, (int) $r->input('rider_id')) ? 'Given to '.$order->fresh()->rider?->name.' · task sent' : ''),
            'cash' => (DeliveryService::receiveCash($order, $u) ? 'Cash received · '.$order->number.' paid' : ''),
            'cancel' => (DeliveryService::cancel($order, $u, (string) $r->input('reason')) ? 'Delivery cancelled' : ''),
            default => abort(404),
        };

        return $r->expectsJson() ? response()->json(['message' => $msg]) : back()->with('ok', $msg);
    }

    public function report(Request $r)
    {
        abort_unless(Delivery::on(), 403, 'Home delivery is switched off in Company settings.');
        $u = $r->user();
        abort_unless($u->hasAnyPerm(['delivery.manage', 'payroll.manage', 'reports.management', 'delivery.order']), 403);
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $r->input('month')) ? $r->input('month') : now()->format('Y-m');
        $branches = ($u->isGroupWide() || $u->hasAnyPerm(['payroll.manage', 'delivery.order'])) ? Location::where('type', 'BRANCH')->orderBy('name')->get() : Location::where('id', $u->location_id)->get();
        $loc = $r->integer('location') ?: null;
        if (! $loc && $branches->count() === 1) {
            $loc = $branches->first()->id;
        }
        abort_if($loc && ! $branches->contains('id', $loc), 403);
        $rows = DeliveryService::riderStats($month, $loc);
        $start = \Illuminate\Support\Carbon::parse($month.'-01');
        $byBranch = PosOrder::where('delivery_status', 'DELIVERED')->whereBetween('delivered_at', [$start, $start->copy()->endOfMonth()])
            ->whereIn('location_id', $branches->pluck('id'))->selectRaw('location_id, count(*) n, sum(total) sales, sum(rider_fee) fees')->groupBy('location_id')->get()->keyBy('location_id');

        return view('delivery.report', compact('rows', 'month', 'branches', 'loc', 'byBranch'));
    }
}
