<?php

namespace App\Services;

use App\Exceptions\BizException;
use App\Models\Account;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Models\MoneyAccount;
use App\Models\PaymentMethod;
use App\Support\Features;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class LedgerService
{
    public const CHART = [
        ['1010', 'Cash in Hand', 'ASSET'], ['1020', 'Bank Accounts', 'ASSET'], ['1030', 'MFS Wallets', 'ASSET'],
        ['1040', 'Petty Cash', 'ASSET'], ['1050', 'POS Cash Drawers', 'ASSET'], ['1060', 'Card Clearing', 'ASSET'],
        ['1100', 'Accounts Receivable (Catering)', 'ASSET'], ['1200', 'Inventory', 'ASSET'], ['1300', 'Employee Advances', 'ASSET'],
        ['2000', 'Accounts Payable (Vendors)', 'LIABILITY'], ['2100', 'Employee Payables (Refunds)', 'LIABILITY'],
        ['2200', 'Salary Payable', 'LIABILITY'], ['2300', 'VAT Payable', 'LIABILITY'],
        ['3000', 'Owner Equity / Opening Balance', 'EQUITY'],
        ['4000', 'Food Sales', 'INCOME'], ['4100', 'Catering Revenue', 'INCOME'], ['4200', 'Service Charge Income', 'INCOME'],
        ['5000', 'Cost of Goods Sold', 'EXPENSE'], ['5100', 'Wastage & Stock Loss', 'EXPENSE'],
        ['6000', 'Salaries & Wages', 'EXPENSE'], ['6100', 'Rent', 'EXPENSE'], ['6200', 'Utilities', 'EXPENSE'],
        ['6300', 'Gas & Fuel', 'EXPENSE'], ['6400', 'Repairs & Maintenance', 'EXPENSE'], ['6500', 'Transport', 'EXPENSE'],
        ['6900', 'Other Expenses', 'EXPENSE'],
    ];

    protected static array $ids = [];

    public static function id(string $code): int
    {
        if (! isset(static::$ids[$code])) {
            $id = Account::where('code', $code)->value('id');
            if (! $id) {
                throw new BizException("Account $code missing from chart of accounts.");
            }
            static::$ids[$code] = $id;
        }

        return static::$ids[$code];
    }

    public static function flush(): void
    {
        static::$ids = [];
    }

    public static function codeForMoneyType(string $type): string
    {
        return ['CASH' => '1010', 'BANK' => '1020', 'MFS' => '1030', 'PETTY' => '1040', 'DRAWER' => '1050', 'CARD' => '1060'][$type] ?? '1010';
    }

    public static function moneyLine(MoneyAccount $ma, float $debit, float $credit, ?string $memo = null): array
    {
        return ['account_id' => $ma->account_id, 'money_account_id' => $ma->id, 'debit' => $debit, 'credit' => $credit, 'memo' => $memo, 'location_id' => $ma->location_id];
    }

    /** Block a payment that would take a cash / bank / MFS / petty account below zero. */
    public static function ensureFunds(MoneyAccount $ma, float $amount): void
    {
        if (! static::enabled()) {
            return;
        }
        $bal = $ma->balance();
        if ($bal + 0.009 < $amount) {
            throw new BizException('Not enough balance in '.$ma->name.' (৳'.number_format($bal).' available, ৳'.number_format($amount).' needed). Transfer funds first.');
        }
    }

    public static function enabled(): bool
    {
        return Features::enabled('acc.core', null, true);
    }

    /**
     * lines: [['code'=>'1200' | 'account_id'=>.., 'debit'=>, 'credit'=>, 'location_id'?, 'money_account_id'?, 'user_id'?, 'vendor_id'?, 'memo'?], ...]
     */
    public static function post(string $memo, array $lines, ?Model $source = null, ?int $locationId = null, $date = null, bool $force = false): ?JournalEntry
    {
        if (! $force && ! static::enabled()) {
            return null; // accounting not activated yet: operations keep working, no journals
        }
        $date = $date ? Carbon::parse($date) : today();
        if (FiscalPeriod::where('month', $date->format('Y-m'))->where('closed', true)->exists()) {
            throw new BizException('Accounting period '.$date->format('M Y').' is closed.');
        }
        $lines = array_values(array_filter($lines, fn ($l) => round($l['debit'] ?? 0, 2) != 0 || round($l['credit'] ?? 0, 2) != 0));
        $dr = round(array_sum(array_map(fn ($l) => round($l['debit'] ?? 0, 2), $lines)), 2);
        $cr = round(array_sum(array_map(fn ($l) => round($l['credit'] ?? 0, 2), $lines)), 2);
        if (abs($dr - $cr) > 0.009) {
            throw new BizException("Journal not balanced (Dr $dr / Cr $cr).");
        }
        if (! $lines) {
            return null;
        }

        return DB::transaction(function () use ($memo, $lines, $source, $locationId, $date) {
            $je = JournalEntry::create([
                'number' => Numbering::next('JE', 'journal_entries'), 'date' => $date, 'memo' => $memo,
                'source_type' => $source ? class_basename($source) : null, 'source_id' => $source?->getKey(),
                'location_id' => $locationId, 'posted_by' => auth()->id(),
            ]);
            foreach ($lines as $l) {
                $je->lines()->create([
                    'account_id' => $l['account_id'] ?? static::id($l['code']),
                    'location_id' => $l['location_id'] ?? $locationId,
                    'money_account_id' => $l['money_account_id'] ?? null,
                    'user_id' => $l['user_id'] ?? null,
                    'vendor_id' => $l['vendor_id'] ?? null,
                    'debit' => round($l['debit'] ?? 0, 2),
                    'credit' => round($l['credit'] ?? 0, 2),
                    'memo' => $l['memo'] ?? null,
                ]);
            }

            return $je;
        });
    }

    /** Money account used for a payment method (branch drawer for cash at a branch). */
    public static function moneyAccountFor(int $paymentMethodId, ?int $locationId = null): MoneyAccount
    {
        $pm = PaymentMethod::findOrFail($paymentMethodId);
        if ($pm->type === 'CASH' && $locationId) {
            $drawer = MoneyAccount::where('type', 'DRAWER')->where('location_id', $locationId)->first();
            if ($drawer) {
                return $drawer;
            }
        }
        $ma = MoneyAccount::where('payment_method_id', $pm->id)->where('active', true)->first()
            ?? MoneyAccount::where('type', $pm->type === 'CARD' ? 'BANK' : $pm->type)->where('active', true)->first();
        if (! $ma) {
            throw new BizException('No money account set up for '.$pm->name.'.');
        }

        return $ma;
    }
}
