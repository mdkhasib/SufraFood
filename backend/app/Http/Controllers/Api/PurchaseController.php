<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MoneyAccount;
use App\Models\PaymentMethod;
use App\Models\PurchaseAssignment;
use App\Models\ReimbursementClaim;
use App\Models\Requisition;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WalletTransaction;
use App\Services\PurchaseService;
use App\Support\P;
use Illuminate\Http\Request;

class PurchaseController extends Controller
{
    public function mine(Request $r)
    {
        $q = PurchaseAssignment::where('buyer_id', $r->user()->id);
        $r->input('state') === 'done' ? $q->whereIn('status', ['SETTLED']) : $q->whereNotIn('status', ['SETTLED']);

        return $q->orderBy('deadline_at')->limit(50)->get()->map(fn ($pa) => P::purchase($pa))->values();
    }

    public function show(Request $r, PurchaseAssignment $assignment)
    {
        abort_unless($assignment->buyer_id === $r->user()->id || $r->user()->hasPerm('purchase.manage'), 403);

        return P::purchase($assignment) + ['vendors' => Vendor::orderBy('name')->get(['id', 'name'])];
    }

    public function accept(Request $r, PurchaseAssignment $assignment)
    {
        return ['message' => 'Accepted', 'purchase' => P::purchase(PurchaseService::accept($assignment, $r->user()))];
    }

    public function receipt(Request $r, PurchaseAssignment $assignment)
    {
        $path = $r->hasFile('photo') ? $r->file('photo')->store('receipts', 'public') : null;
        $lines = $r->input('lines');
        if (is_string($lines)) {
            $lines = json_decode($lines, true) ?: [];
        }
        $lines = collect($lines ?? [])->mapWithKeys(fn ($v, $k) => [(int) $k => $v])->all();
        $pa = PurchaseService::submitReceipt($assignment, $r->user(), $lines, $path, $r->input('note'), $r->input('vendor_id') ? (int) $r->input('vendor_id') : null);

        return ['message' => 'Receipt submitted · ৳'.number_format($pa->actual_amount), 'purchase' => P::purchase($pa)];
    }

    public function delivered(Request $r, PurchaseAssignment $assignment)
    {
        return ['message' => 'Marked delivered', 'purchase' => P::purchase(PurchaseService::markDelivered($assignment, $r->user()))];
    }

    public function wallet(Request $r)
    {
        $u = $r->user();

        return PurchaseService::wallet($u) + [
            'transactions' => WalletTransaction::where('user_id', $u->id)->latest('id')->limit(40)->get()->map(fn ($t) => ['type' => $t->type, 'amount' => $t->amount, 'note' => $t->note, 'at' => $t->created_at->format('d M, h:i A')]),
            'claims' => ReimbursementClaim::where('user_id', $u->id)->latest('id')->limit(30)->get()->map(fn ($c) => ['id' => $c->id, 'amount' => $c->amount, 'description' => $c->description, 'status' => $c->status, 'at' => $c->created_at->format('d M'), 'paid_at' => $c->paid_at?->format('d M')]),
        ];
    }

    public function claim(Request $r)
    {
        $r->validate(['amount' => 'required|numeric|min:1', 'description' => 'required|string']);
        $path = $r->hasFile('photo') ? $r->file('photo')->store('receipts', 'public') : null;
        PurchaseService::createClaim($r->user(), (float) $r->amount, $r->description, $path);

        return ['message' => 'Refund claim sent for approval'];
    }

    // ---------- purchase manager ----------
    public function requests(Request $r)
    {
        abort_unless($r->user()->hasPerm('purchase.manage'), 403);

        return Requisition::with('lines.item', 'fromLocation', 'toLocation', 'requester')->where('type', 'PURCHASE')
            ->whereIn('status', ['SENT_TO_FULFILLER', 'IN_PROGRESS', 'DELIVERED'])->latest('id')->get()
            ->map(fn ($x) => P::requisitionBrief($x) + ['purchase' => $x->purchaseAssignment ? P::purchase($x->purchaseAssignment) : null])->values();
    }

    public function options(Request $r)
    {
        $u = $r->user();

        return [
            'buyers' => User::whereHas('role', fn ($q) => $q->where('key', 'buyer'))->where('status', 'ACTIVE')->get()->map(fn ($b) => ['id' => $b->id, 'name' => $b->name, 'wallet' => PurchaseService::walletBalance($b)]),
            'vendors' => Vendor::orderBy('name')->get(['id', 'name', 'terms', 'credit_days']),
            'payment_methods' => PaymentMethod::where('active', true)->where('type', '!=', 'CARD')->get(['id', 'name', 'type']),
            'petty_funds' => MoneyAccount::where('type', 'PETTY')->where('active', true)->get(['id', 'name']),
            'fundings' => collect(PurchaseService::FUNDING)->filter(fn ($f) => $u->featureOn($f[1]))->map(fn ($f, $k) => ['key' => $k, 'label' => $f[0]])->values(),
        ];
    }

    public function assign(Request $r, Requisition $requisition)
    {
        $r->validate(['buyer_id' => 'required|exists:users,id', 'deadline_at' => 'required|date', 'funding' => 'required|string']);
        $pa = PurchaseService::assign($requisition, $r->user(), $r->only('buyer_id', 'deadline_at', 'funding', 'payment_method_id', 'vendor_id', 'petty_account_id'));

        return ['message' => 'Assigned to '.$pa->buyer->name, 'purchase' => P::purchase($pa)];
    }
}
