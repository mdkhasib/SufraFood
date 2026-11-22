<?php

namespace App\Services;

use App\Exceptions\BizException;
use App\Models\MoneyAccount;
use App\Models\PurchaseAssignment;
use App\Models\ReimbursementClaim;
use App\Models\Requisition;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Models\VendorPayment;
use App\Models\WalletTransaction;
use App\Support\Features;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PurchaseService
{
    public const FUNDING = [
        'CASH_ADVANCE' => ['Cash advance', 'purchase.cash_advance'],
        'PETTY_CASH' => ['Petty cash', 'purchase.petty_cash'],
        'OWN_MONEY' => ['Own money (refund)', 'purchase.reimbursement'],
        'VENDOR_CREDIT' => ['Vendor credit', 'purchase.vendor_credit'],
    ];

    public static function assign(Requisition $r, User $by, array $d): PurchaseAssignment
    {
        if (! $by->hasPerm('purchase.manage')) {
            throw new BizException('Only the purchase manager can assign buyers.');
        }
        if ($r->type !== 'PURCHASE' || $r->status !== 'SENT_TO_FULFILLER') {
            throw new BizException('This purchase request is not ready for a buyer.');
        }
        $funding = $d['funding'] ?? '';
        if (! isset(static::FUNDING[$funding]) || ! $by->featureOn(static::FUNDING[$funding][1])) {
            throw new BizException('Choose an activated funding type.');
        }
        $buyer = User::findOrFail($d['buyer_id']);
        if (! $buyer->hasPerm('purchase.buy')) {
            throw new BizException($buyer->name.' is not a buyer.');
        }
        if ($funding === 'VENDOR_CREDIT' && empty($d['vendor_id'])) {
            throw new BizException('Choose the vendor for credit purchase.');
        }
        if ($funding === 'PETTY_CASH' && empty($d['petty_account_id'])) {
            throw new BizException('Choose the petty cash fund.');
        }
        $deadline = \App\Support\Dt::parse($d['deadline_at']);

        return DB::transaction(function () use ($r, $by, $d, $funding, $buyer, $deadline) {
            $pa = PurchaseAssignment::create([
                'number' => Numbering::next('PA', 'purchase_assignments'), 'requisition_id' => $r->id, 'buyer_id' => $buyer->id,
                'assigned_by' => $by->id, 'deadline_at' => $deadline, 'funding' => $funding,
                'payment_method_id' => $d['payment_method_id'] ?? null, 'vendor_id' => $d['vendor_id'] ?? null,
                'petty_account_id' => $d['petty_account_id'] ?? null, 'status' => 'ASSIGNED',
            ]);
            foreach ($r->lines as $line) {
                $line->update(['qty_approved' => $line->qty_requested]);
            }
            RequisitionService::setStatus($r, 'IN_PROGRESS', $by, 'Buyer: '.$buyer->name.' · due '.$deadline->format('d M h:i A').' · '.static::FUNDING[$funding][0], ['accepted_by' => $by->id, 'accepted_at' => now()]);
            Notifier::send($buyer, 'Purchase assigned: '.$r->lines->map(fn ($l) => $l->item?->name.' '.$l->qty_requested.$l->item?->unit)->join(', '), 'Deadline '.$deadline->format('d M h:i A').' · '.static::FUNDING[$funding][0], 'purchase', '/purchases');

            return $pa;
        });
    }

    protected static function ensureBuyer(PurchaseAssignment $pa, User $u): void
    {
        if ($pa->buyer_id !== $u->id && ! $u->is_super_admin) {
            throw new BizException('This purchase is assigned to someone else.');
        }
    }

    public static function accept(PurchaseAssignment $pa, User $u): PurchaseAssignment
    {
        static::ensureBuyer($pa, $u);
        if ($pa->status !== 'ASSIGNED') {
            throw new BizException('Already accepted.');
        }
        $pa->update(['status' => 'ACCEPTED', 'accepted_at' => now()]);
        Notifier::send($pa->assigned_by, $u->name.' accepted '.$pa->number, null, 'purchase');

        return $pa;
    }

    /** lines: [requisition_line_id => ['qty'=>, 'unit_price'=>]] */
    public static function submitReceipt(PurchaseAssignment $pa, User $u, array $lines, ?string $photo, ?string $note = null, ?int $vendorId = null): PurchaseAssignment
    {
        static::ensureBuyer($pa, $u);
        if (! in_array($pa->status, ['ASSIGNED', 'ACCEPTED'])) {
            throw new BizException('Receipt already submitted.');
        }
        if (! $photo) {
            throw new BizException('Upload the receipt photo.');
        }
        $r = $pa->requisition()->with('lines.item')->first();
        $total = 0;
        $flag = false;
        $flagPct = (float) Features::setting('purchase.price_flag_percent', 10);
        DB::transaction(function () use ($pa, $r, $lines, &$total, &$flag, $flagPct) {
            $pa->lines()->delete();
            foreach ($r->lines as $line) {
                $in = $lines[$line->id] ?? null;
                $qty = (float) ($in['qty'] ?? $line->qty_requested);
                $price = (float) ($in['unit_price'] ?? $line->est_price);
                if ($qty <= 0) {
                    continue;
                }
                if ($price <= 0) {
                    throw new BizException('Enter the price for '.$line->item?->name.'.');
                }
                $pa->lines()->create(['requisition_line_id' => $line->id, 'qty' => $qty, 'unit_price' => $price]);
                $line->update(['qty_sent' => $qty, 'est_price' => $price]);
                $total += $qty * $price;
                $last = (float) $line->item?->last_price;
                if ($last > 0 && (($price - $last) / $last * 100) > $flagPct) {
                    $flag = true;
                }
            }
        });
        if ($total <= 0) {
            throw new BizException('Nothing bought.');
        }
        if ($pa->funding === 'CASH_ADVANCE' && static::walletBalance($u) + 0.01 < $total) {
            throw new BizException('Your advance balance (৳'.number_format(static::walletBalance($u)).') is less than ৳'.number_format($total).'. Ask Accounts for more advance or use another funding.');
        }
        if ($pa->funding === 'OWN_MONEY' && $u->own_money_limit !== null && $total > $u->own_money_limit) {
            throw new BizException('Own-money purchases above ৳'.number_format($u->own_money_limit).' need pre-approval.');
        }
        $pa->update(['status' => 'BOUGHT', 'bought_at' => now(), 'actual_amount' => round($total, 2), 'receipt_photo' => $photo, 'receipt_note' => $note, 'price_flag' => $flag, 'vendor_id' => $vendorId ?? $pa->vendor_id]);
        Notifier::send($pa->assigned_by, ($flag ? '⚠ Price check: ' : '').$pa->number.' bought ৳'.number_format($total), $u->name.($flag ? ' · price above benchmark' : ''), $flag ? 'warning' : 'purchase', '/requisitions/'.$r->id);

        return $pa->fresh();
    }

    public static function markDelivered(PurchaseAssignment $pa, User $u): PurchaseAssignment
    {
        static::ensureBuyer($pa, $u);
        if ($pa->status !== 'BOUGHT') {
            throw new BizException('Submit the receipt first.');
        }
        $pa->update(['status' => 'DELIVERED', 'delivered_at' => now()]);
        $r = $pa->requisition;
        RequisitionService::setStatus($r, 'DELIVERED', $u, 'Delivered by buyer');
        Notifier::send(Notifier::usersWithPerm('req.receive', $r->from_location_id)->pluck('id')->push($r->requester_id)->all(), 'Goods arrived: '.$r->number, 'Check and confirm goods received', 'delivery', '/requisitions/'.$r->id);

        return $pa->fresh();
    }

    /** Called by RequisitionService::receive for PURCHASE: money effects by funding. */
    public static function onGoodsReceived(Requisition $r, User $by): void
    {
        $pa = $r->purchaseAssignment()->with('lines.requisitionLine.item', 'buyer', 'vendor')->first();
        if (! $pa) {
            return;
        }
        $amount = 0;
        foreach ($pa->lines as $pl) {
            $rl = $pl->requisitionLine;
            $qty = (float) ($rl->qty_received ?? $pl->qty);
            $amount += $qty * $pl->unit_price;
            $rl->item?->update(['last_price' => $pl->unit_price]);
        }
        $amount = round($amount, 2);
        $memo = 'Purchase '.$r->number.' ('.$pa->buyer->name.')';
        $loc = $r->from_location_id;
        $inv = ['code' => '1200', 'debit' => $amount, 'location_id' => $loc];
        switch ($pa->funding) {
            case 'CASH_ADVANCE':
                WalletTransaction::create(['user_id' => $pa->buyer_id, 'type' => 'SPEND', 'amount' => $amount, 'ref_type' => 'PurchaseAssignment', 'ref_id' => $pa->id, 'note' => $memo, 'created_by' => $by->id]);
                LedgerService::post($memo, [$inv, ['code' => '1300', 'credit' => $amount, 'user_id' => $pa->buyer_id]], $pa, $loc);
                break;
            case 'PETTY_CASH':
                $ma = MoneyAccount::findOrFail($pa->petty_account_id);
                LedgerService::post($memo, [$inv, LedgerService::moneyLine($ma, 0, $amount)], $pa, $loc);
                break;
            case 'OWN_MONEY':
                $claim = ReimbursementClaim::create(['user_id' => $pa->buyer_id, 'purchase_assignment_id' => $pa->id, 'amount' => $amount, 'description' => $memo, 'receipt_photo' => $pa->receipt_photo]);
                LedgerService::post($memo, [$inv, ['code' => '2100', 'credit' => $amount, 'user_id' => $pa->buyer_id]], $pa, $loc);
                ApprovalService::start($claim, $pa->buyer);
                break;
            case 'VENDOR_CREDIT':
                $vendor = $pa->vendor;
                VendorBill::create(['number' => Numbering::next('VB', 'vendor_bills'), 'vendor_id' => $vendor->id, 'purchase_assignment_id' => $pa->id, 'amount' => $amount, 'bill_date' => today(), 'due_date' => today()->addDays(max(0, (int) $vendor->credit_days))]);
                LedgerService::post($memo, [$inv, ['code' => '2000', 'credit' => $amount, 'vendor_id' => $vendor->id]], $pa, $loc);
                break;
        }
        $pa->update(['status' => 'SETTLED', 'actual_amount' => $amount]);
    }

    // ---------------- Wallet ----------------

    public static function walletBalance(User $u): float
    {
        $sum = fn ($type) => (float) WalletTransaction::where('user_id', $u->id)->where('type', $type)->sum('amount');

        return round($sum('ISSUE') - $sum('SPEND') - $sum('RETURN'), 2);
    }

    public static function wallet(User $u): array
    {
        $sum = fn ($type) => (float) WalletTransaction::where('user_id', $u->id)->where('type', $type)->sum('amount');
        $pendingSpend = (float) PurchaseAssignment::where('buyer_id', $u->id)->where('funding', 'CASH_ADVANCE')->whereIn('status', ['BOUGHT', 'DELIVERED'])->sum('actual_amount');

        return [
            'advance_held' => round($sum('ISSUE') - $sum('RETURN'), 2),
            'spent' => round($sum('SPEND'), 2),
            'spent_pending' => round($pendingSpend, 2),
            'balance' => round(static::walletBalance($u) - $pendingSpend, 2),
            'company_owes' => round((float) ReimbursementClaim::where('user_id', $u->id)->whereIn('status', ['PENDING', 'APPROVED'])->sum('amount'), 2),
            'receipts_pending' => PurchaseAssignment::where('buyer_id', $u->id)->whereIn('status', ['BOUGHT', 'DELIVERED'])->count(),
            'advance_limit' => $u->advance_limit,
        ];
    }

    public static function issueAdvance(User $by, User $buyer, float $amount, int $moneyAccountId, ?string $note = null): WalletTransaction
    {
        if (! $by->hasPerm('purchase.advance')) {
            throw new BizException('You cannot issue advances.');
        }
        if ($amount <= 0) {
            throw new BizException('Enter an amount.');
        }
        if ($buyer->advance_limit !== null && static::walletBalance($buyer) + $amount > $buyer->advance_limit) {
            throw new BizException($buyer->name.'\'s advance limit is ৳'.number_format($buyer->advance_limit).' (holding ৳'.number_format(static::walletBalance($buyer)).').');
        }
        $overdue = PurchaseAssignment::where('buyer_id', $buyer->id)->whereIn('status', ['ASSIGNED', 'ACCEPTED'])->where('deadline_at', '<', now()->subHours(24))->count();
        if ($overdue) {
            throw new BizException($buyer->name.' has '.$overdue.' purchase(s) without receipt for over 24 h. Settle them first.');
        }
        $ma = MoneyAccount::findOrFail($moneyAccountId);
        LedgerService::ensureFunds($ma, $amount);

        return DB::transaction(function () use ($by, $buyer, $amount, $ma, $note) {
            $tx = WalletTransaction::create(['user_id' => $buyer->id, 'type' => 'ISSUE', 'amount' => $amount, 'money_account_id' => $ma->id, 'note' => $note, 'created_by' => $by->id]);
            LedgerService::post('Cash advance to '.$buyer->name, [['code' => '1300', 'debit' => $amount, 'user_id' => $buyer->id], LedgerService::moneyLine($ma, 0, $amount)], $tx);
            Notifier::send($buyer, 'Advance received ৳'.number_format($amount), 'Via '.$ma->name, 'success', '/wallet');

            return $tx;
        });
    }

    public static function returnAdvance(User $by, User $buyer, float $amount, int $moneyAccountId): WalletTransaction
    {
        if (! $by->hasPerm('purchase.advance')) {
            throw new BizException('You cannot record advance returns.');
        }
        if ($amount <= 0 || $amount > static::walletBalance($buyer) + 0.01) {
            throw new BizException('Return amount is more than the advance balance.');
        }
        $ma = MoneyAccount::findOrFail($moneyAccountId);

        return DB::transaction(function () use ($by, $buyer, $amount, $ma) {
            $tx = WalletTransaction::create(['user_id' => $buyer->id, 'type' => 'RETURN', 'amount' => $amount, 'money_account_id' => $ma->id, 'created_by' => $by->id]);
            LedgerService::post('Advance returned by '.$buyer->name, [LedgerService::moneyLine($ma, $amount, 0), ['code' => '1300', 'credit' => $amount, 'user_id' => $buyer->id]], $tx);

            return $tx;
        });
    }

    public static function createClaim(User $u, float $amount, string $description, ?string $photo): ReimbursementClaim
    {
        if (! $u->featureOn('purchase.reimbursement')) {
            throw new BizException('Refund claims are not enabled.');
        }
        if (! $photo) {
            throw new BizException('Upload the payment receipt.');
        }
        $c = ReimbursementClaim::create(['user_id' => $u->id, 'amount' => $amount, 'description' => $description, 'receipt_photo' => $photo]);
        ApprovalService::start($c, $u);

        return $c;
    }

    public static function payClaim(ReimbursementClaim $c, User $by, int $moneyAccountId): ReimbursementClaim
    {
        if (! $by->hasPerm('acc.manage')) {
            throw new BizException('Only Accounts can pay refunds.');
        }
        if ($c->status !== 'APPROVED') {
            throw new BizException('Claim must be approved first.');
        }
        $ma = MoneyAccount::findOrFail($moneyAccountId);
        LedgerService::ensureFunds($ma, $c->amount);
        DB::transaction(function () use ($c, $by, $ma) {
            $hasPayable = (bool) $c->purchase_assignment_id;
            LedgerService::post('Refund to '.$c->user->name, [
                $hasPayable ? ['code' => '2100', 'debit' => $c->amount, 'user_id' => $c->user_id] : ['code' => '6900', 'debit' => $c->amount, 'location_id' => $c->user->location_id],
                LedgerService::moneyLine($ma, 0, $c->amount),
            ], $c);
            $c->update(['status' => 'PAID', 'paid_from' => $ma->id, 'paid_at' => now()]);
            Notifier::send($c->user_id, 'Refund paid ৳'.number_format($c->amount), 'Via '.$ma->name, 'success', '/wallet');
        });

        return $c->fresh();
    }

    public static function payVendorBill(VendorBill $bill, User $by, int $moneyAccountId, float $amount, ?string $ref = null): VendorPayment
    {
        if (! $by->hasPerm('acc.manage')) {
            throw new BizException('Only Accounts can pay vendors.');
        }
        $due = round($bill->amount - $bill->paid_amount, 2);
        if ($amount <= 0 || $amount > $due + 0.01) {
            throw new BizException('Amount must be between 1 and ৳'.number_format($due).'.');
        }
        $ma = MoneyAccount::findOrFail($moneyAccountId);
        LedgerService::ensureFunds($ma, $amount);

        return DB::transaction(function () use ($bill, $by, $ma, $amount, $ref) {
            $p = VendorPayment::create(['vendor_bill_id' => $bill->id, 'money_account_id' => $ma->id, 'amount' => $amount, 'reference' => $ref, 'date' => today(), 'created_by' => $by->id]);
            LedgerService::post('Payment to '.$bill->vendor->name.' ('.$bill->number.')', [['code' => '2000', 'debit' => $amount, 'vendor_id' => $bill->vendor_id], LedgerService::moneyLine($ma, 0, $amount)], $p);
            $paid = round($bill->paid_amount + $amount, 2);
            $bill->update(['paid_amount' => $paid, 'status' => $paid + 0.01 >= $bill->amount ? 'PAID' : 'PARTIAL']);

            return $p;
        });
    }

    public static function vendorOutstanding(Vendor $v): float
    {
        return round((float) $v->bills()->sum('amount') - (float) $v->bills()->sum('paid_amount'), 2);
    }
}
