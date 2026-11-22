<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\MoneyAccount;
use App\Models\PurchaseAssignment;
use App\Models\ReimbursementClaim;
use App\Models\Requisition;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Models\WalletTransaction;
use App\Services\PurchaseService;
use Illuminate\Http\Request;

class PurchaseController extends Controller
{
    public function index(Request $r)
    {
        $u = $r->user();
        $toAssign = $u->hasPerm('purchase.manage') ? Requisition::with('lines.item', 'fromLocation')->where('type', 'PURCHASE')->where('status', 'SENT_TO_FULFILLER')->latest('id')->get() : collect();
        $pending = $u->hasPerm('purchase.manage') ? Requisition::with('lines.item', 'fromLocation')->where('type', 'PURCHASE')->where('status', 'PENDING_APPROVAL')->latest('id')->get() : collect();
        $active = PurchaseAssignment::with('requisition.lines.item', 'buyer', 'vendor')->whereNotIn('status', ['SETTLED'])
            ->when(! $u->hasPerm('purchase.manage'), fn ($q) => $q->where('buyer_id', $u->id))->orderBy('deadline_at')->get();
        $done = PurchaseAssignment::with('requisition.lines.item', 'buyer', 'vendor')->where('status', 'SETTLED')
            ->when(! $u->hasPerm('purchase.manage'), fn ($q) => $q->where('buyer_id', $u->id))->latest('id')->limit(30)->get();

        return view('pur.index', compact('toAssign', 'pending', 'active', 'done'));
    }

    public function assign(Request $r, Requisition $requisition)
    {
        $pa = PurchaseService::assign($requisition, $r->user(), $r->only('buyer_id', 'deadline_at', 'funding', 'payment_method_id', 'vendor_id', 'petty_account_id'));

        return back()->with('ok', 'Assigned to '.$pa->buyer->name);
    }

    public function assignment(Request $r, PurchaseAssignment $assignment)
    {
        abort_unless($assignment->buyer_id === $r->user()->id || $r->user()->hasPerm('purchase.manage') || $r->user()->is_super_admin, 403);

        return view('pur.assignment', ['pa' => $assignment->load('requisition.lines.item', 'buyer', 'vendor', 'paymentMethod', 'lines'), 'vendors' => Vendor::orderBy('name')->get()]);
    }

    public function assignmentAction(Request $r, PurchaseAssignment $assignment, string $action)
    {
        $u = $r->user();
        match ($action) {
            'accept' => PurchaseService::accept($assignment, $u),
            'receipt' => PurchaseService::submitReceipt($assignment, $u, collect($r->input('lines', []))->mapWithKeys(fn ($v, $k) => [(int) $k => $v])->all(), $r->hasFile('photo') ? $r->file('photo')->store('receipts', 'public') : null, $r->note, $r->vendor_id ? (int) $r->vendor_id : null),
            'delivered' => PurchaseService::markDelivered($assignment, $u),
            default => abort(404),
        };

        return back()->with('ok', 'Updated');
    }

    public function wallets(Request $r)
    {
        $u = $r->user();
        $buyers = User::whereHas('role', fn ($q) => $q->where('key', 'buyer'))->when(! $u->hasAnyPerm(['purchase.advance', 'purchase.manage']), fn ($q) => $q->where('id', $u->id))->get()
            ->map(fn ($b) => ['user' => $b, 'w' => PurchaseService::wallet($b), 'tx' => WalletTransaction::where('user_id', $b->id)->latest('id')->limit(8)->get()]);
        $money = MoneyAccount::where('active', true)->whereIn('type', ['CASH', 'BANK', 'MFS'])->get();

        return view('pur.wallets', compact('buyers', 'money'));
    }

    public function walletAction(Request $r, User $user, string $action)
    {
        $r->validate(['amount' => 'required|numeric|min:1', 'money_account_id' => 'required']);
        $action === 'issue'
            ? PurchaseService::issueAdvance($r->user(), $user, (float) $r->amount, (int) $r->money_account_id, $r->note)
            : PurchaseService::returnAdvance($r->user(), $user, (float) $r->amount, (int) $r->money_account_id);

        return back()->with('ok', $action === 'issue' ? 'Advance issued' : 'Return recorded');
    }

    public function claims(Request $r)
    {
        $u = $r->user();
        $claims = ReimbursementClaim::with('user', 'paidFrom')->when(! $u->hasAnyPerm(['acc.manage', 'purchase.manage']), fn ($q) => $q->where('user_id', $u->id))->latest('id')->get();
        $money = MoneyAccount::where('active', true)->whereIn('type', ['CASH', 'BANK', 'MFS'])->get();

        return view('pur.claims', compact('claims', 'money'));
    }

    public function newClaim(Request $r)
    {
        $r->validate(['amount' => 'required|numeric|min:1', 'description' => 'required']);
        PurchaseService::createClaim($r->user(), (float) $r->amount, $r->description, $r->hasFile('photo') ? $r->file('photo')->store('receipts', 'public') : null);

        return back()->with('ok', 'Refund claim sent for approval');
    }

    public function payClaim(Request $r, ReimbursementClaim $claim)
    {
        PurchaseService::payClaim($claim, $r->user(), (int) $r->money_account_id);

        return back()->with('ok', 'Refund paid');
    }

    public function bills(Request $r)
    {
        $bills = VendorBill::with('vendor', 'payments.moneyAccount')->orderByRaw("status = 'PAID'")->orderBy('due_date')->get();
        $money = MoneyAccount::where('active', true)->whereIn('type', ['CASH', 'BANK', 'MFS'])->get();
        $vendors = Vendor::all()->map(fn ($v) => ['v' => $v, 'due' => PurchaseService::vendorOutstanding($v)])->filter(fn ($x) => $x['due'] > 0);

        return view('pur.bills', compact('bills', 'money', 'vendors'));
    }

    public function payBill(Request $r, VendorBill $bill)
    {
        PurchaseService::payVendorBill($bill, $r->user(), (int) $r->money_account_id, (float) $r->amount, $r->reference);

        return back()->with('ok', 'Payment recorded');
    }
}
