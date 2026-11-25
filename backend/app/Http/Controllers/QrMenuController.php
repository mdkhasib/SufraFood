<?php

namespace App\Http\Controllers;

use App\Models\DiningTable;
use App\Models\Item;
use App\Models\PosOrder;
use App\Models\TableCall;
use App\Services\QrOrderService;
use App\Support\Features;
use Illuminate\Http\Request;

/** Public pages the customer opens by scanning the QR code on a table (no login). */
class QrMenuController extends Controller
{
    protected function menu()
    {
        return Item::where('type', 'MENU')->where('active', true)->orderBy('category')->orderBy('name')->get(['id', 'name', 'name_bn', 'category', 'sale_price', 'image'])
            ->map(fn ($i) => ['id' => $i->id, 'name' => $i->name, 'name_bn' => $i->name_bn, 'category' => $i->category ?: 'Menu', 'price' => (float) $i->sale_price, 'img' => $i->imageUrl()]);
    }

    public function table(Request $r, string $token)
    {
        if (! QrOrderService::on()) {
            return response()->view('qr.off', [], 503);
        }
        $t = QrOrderService::table($token);
        $cfg = [
            'order' => (bool) (int) Features::setting('qr.allow_order', 1), 'calls' => (bool) (int) Features::setting('qr.allow_calls', 1),
            'phone' => (bool) (int) Features::setting('qr.ask_phone', 0), 'max' => (int) Features::setting('qr.max_qty', 30),
            'vat' => (float) Features::setting('pos.vat_percent', 5), 'sc' => (float) Features::setting('pos.service_charge_percent', 0),
            'welcome' => Features::setting('qr.welcome'), 'pay' => array_values(QrOrderService::payOptions()), 'pay_note' => Features::setting('qr.pay_note'), 'types' => collect(TableCall::TYPES)->map(fn ($v, $k) => ['key' => $k, 'icon' => $v[0], 'label' => $v[1]])->values(),
        ];

        return view('qr.menu', ['t' => $t, 'menu' => $this->menu(), 'cfg' => $cfg]);
    }

    /** Branch QR (e.g. at the door): the customer picks the table number first. */
    public function branch(Request $r, string $token)
    {
        if (! QrOrderService::on()) {
            return response()->view('qr.off', [], 503);
        }
        $l = QrOrderService::branch($token);

        return view('qr.branch', ['l' => $l, 'tables' => DiningTable::where('location_id', $l->id)->where('active', true)->orderBy('id')->get(['name', 'qr_token'])]);
    }

    public function order(Request $r, string $token)
    {
        $t = QrOrderService::table($token);
        $o = QrOrderService::order($t, $r->only('lines', 'customer_name', 'customer_phone', 'note'));
        $mine = array_unique(array_merge((array) $r->session()->get('qr.orders.'.$t->id, []), [$o->id]));
        $r->session()->put('qr.orders.'.$t->id, $mine);

        return ['message' => 'Your order is in the kitchen', 'number' => $o->number] + $this->statusData($r, $t);
    }

    public function call(Request $r, string $token)
    {
        $t = QrOrderService::table($token);
        $c = QrOrderService::call($t, (string) $r->input('type'), $r->input('note'));
        $mine = array_unique(array_merge((array) $r->session()->get('qr.calls.'.$t->id, []), [$c->id]));
        $r->session()->put('qr.calls.'.$t->id, $mine);

        return ['message' => 'We are on our way'] + $this->statusData($r, $t);
    }

    /** “I paid by bKash / Nagad”: the transaction ID goes to the cashier, who closes the bill. */
    public function pay(Request $r, string $token)
    {
        $t = QrOrderService::table($token);
        $total = QrOrderService::payClaim($t, (array) $r->session()->get('qr.orders.'.$t->id, []), (string) $r->input('method'), (string) $r->input('trx'), $r->input('phone'));

        return ['message' => 'Payment of ৳'.number_format($total).' sent. The cashier will confirm it.'] + $this->statusData($r, $t);
    }

    public function status(Request $r, string $token)
    {
        return $this->statusData($r, QrOrderService::table($token));
    }

    /** Only what this phone ordered / asked for. */
    protected function statusData(Request $r, DiningTable $t): array
    {
        $orderIds = (array) $r->session()->get('qr.orders.'.$t->id, []);
        $callIds = (array) $r->session()->get('qr.calls.'.$t->id, []);
        $orders = PosOrder::with('lines.item')->whereIn('id', $orderIds)->where('dining_table_id', $t->id)->get();
        $calls = TableCall::with('answeredBy')->whereIn('id', $callIds)->where('created_at', '>=', now()->subHours(4))->latest('id')->get();

        return [
            'bill' => $orders->where('status', 'OPEN')->sum('total'),
            'paid' => $orders->isNotEmpty() && $orders->every(fn ($o) => $o->status === 'PAID'),
            'claim' => ($c = $orders->where('status', 'OPEN')->whereNotNull('pay_claim_trx')->first()) ? ['method' => $c->pay_claim_method, 'trx' => $c->pay_claim_trx] : null,
            'unclaimed' => (float) $orders->where('status', 'OPEN')->whereNull('pay_claim_trx')->sum('total'),
            'items' => $orders->flatMap(fn ($o) => $o->lines->map(fn ($l) => ['name' => $l->item->name, 'name_bn' => $l->item->name_bn, 'qty' => (float) $l->qty, 'state' => $l->kot_status]))->values(),
            'calls' => $calls->map(fn ($c) => ['label' => $c->label(), 'status' => $c->status, 'by' => $c->answeredBy?->name, 'at' => $c->created_at->format('h:i A')])->values(),
        ];
    }
}
