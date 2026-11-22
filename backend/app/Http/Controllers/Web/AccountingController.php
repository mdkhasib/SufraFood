<?php

namespace App\Http\Controllers\Web;

use App\Exceptions\BizException;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Expense;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Models\Location;
use App\Models\MoneyAccount;
use App\Services\Audit;
use App\Services\DashboardService;
use App\Services\LedgerService;
use Illuminate\Http\Request;

class AccountingController extends Controller
{
    public function index()
    {
        $money = MoneyAccount::with('location', 'custodian')->where('active', true)->get()->map(fn ($m) => ['m' => $m, 'bal' => $m->balance()]);
        $accounts = Account::orderBy('code')->get()->map(fn ($a) => ['a' => $a, 'bal' => $a->balance()]);
        $periods = FiscalPeriod::orderByDesc('month')->get();

        return view('acc.index', compact('money', 'accounts', 'periods') + ['stockValue' => DashboardService::stockValue()]);
    }

    public function journal(Request $r)
    {
        $q = JournalEntry::with('lines.account', 'location', 'poster')->latest('date')->latest('id');
        if ($r->filled('q')) {
            $q->where(fn ($w) => $w->where('memo', 'like', '%'.$r->q.'%')->orWhere('number', 'like', '%'.$r->q.'%'));
        }
        if ($r->filled('account_id')) {
            $q->whereHas('lines', fn ($w) => $w->where('account_id', $r->account_id));
        }

        return view('acc.journal', ['entries' => $q->paginate(30)->withQueryString(), 'accounts' => Account::orderBy('code')->get(), 'locations' => Location::all(), 'money' => MoneyAccount::where('active', true)->get()]);
    }

    public function entry(JournalEntry $entry)
    {
        return view('acc.entry', ['e' => $entry->load('lines.account', 'lines.location', 'lines.moneyAccount', 'poster')]);
    }

    public function post(Request $r)
    {
        $lines = collect($r->input('lines', []))->filter(fn ($l) => ! empty($l['account_id']) && ((float) ($l['debit'] ?? 0) > 0 || (float) ($l['credit'] ?? 0) > 0))
            ->map(fn ($l) => ['account_id' => (int) $l['account_id'], 'debit' => (float) ($l['debit'] ?? 0), 'credit' => (float) ($l['credit'] ?? 0), 'location_id' => $l['location_id'] ?? null, 'money_account_id' => $l['money_account_id'] ?? null])->values()->all();
        if (count($lines) < 2) {
            throw new BizException('A journal needs at least two lines.');
        }
        $je = LedgerService::post($r->input('memo', 'Manual journal'), $lines, null, null, $r->input('date'));
        Audit::log('journal.manual', $je);

        return redirect()->route('acc.entry', $je)->with('ok', 'Journal posted');
    }

    public function expenses()
    {
        return view('acc.expenses', [
            'items' => Expense::with('account', 'moneyAccount', 'location')->latest('date')->latest('id')->paginate(40),
            'accounts' => Account::where('type', 'EXPENSE')->orderBy('code')->get(), 'money' => MoneyAccount::where('active', true)->get(), 'locations' => Location::all(),
        ]);
    }

    public function saveExpense(Request $r)
    {
        $r->validate(['account_id' => 'required', 'money_account_id' => 'required', 'amount' => 'required|numeric|min:1', 'date' => 'required|date']);
        $ma = MoneyAccount::findOrFail($r->money_account_id);
        LedgerService::ensureFunds($ma, (float) $r->amount);
        $je = LedgerService::post(($r->note ?: 'Expense'), [['account_id' => (int) $r->account_id, 'debit' => (float) $r->amount, 'location_id' => $r->location_id], LedgerService::moneyLine($ma, 0, (float) $r->amount)], null, $r->location_id, $r->date);
        Expense::create($r->only('date', 'account_id', 'money_account_id', 'location_id', 'amount', 'note') + ['created_by' => $r->user()->id, 'journal_entry_id' => $je?->id]);

        return back()->with('ok', 'Expense recorded');
    }

    public function transfer(Request $r)
    {
        $r->validate(['from' => 'required|different:to', 'to' => 'required', 'amount' => 'required|numeric|min:1']);
        $from = MoneyAccount::findOrFail($r->from);
        $to = MoneyAccount::findOrFail($r->to);
        LedgerService::ensureFunds($from, (float) $r->amount);
        LedgerService::post('Transfer '.$from->name.' → '.$to->name.($r->reference ? ' ('.$r->reference.')' : ''), [LedgerService::moneyLine($to, (float) $r->amount, 0), LedgerService::moneyLine($from, 0, (float) $r->amount)]);

        return back()->with('ok', 'Transfer posted');
    }

    public function report(Request $r, string $report)
    {
        $from = $r->input('from', now()->startOfMonth()->toDateString());
        $to = $r->input('to', today()->toDateString());
        $loc = $r->input('location_id') ? (int) $r->location_id : null;
        $locations = Location::orderBy('id')->get();
        $accounts = Account::orderBy('code')->get();
        $data = match ($report) {
            'pnl' => $this->pnl($accounts, $from, $to, $loc, $locations),
            'tb' => ['rows' => $accounts->map(fn ($a) => ['a' => $a, 'dr' => (float) $a->lines()->whereHas('entry', fn ($q) => $q->whereDate('date', '<=', $to))->sum('debit'), 'cr' => (float) $a->lines()->whereHas('entry', fn ($q) => $q->whereDate('date', '<=', $to))->sum('credit')])->filter(fn ($x) => $x['dr'] || $x['cr'])],
            'bs' => ['rows' => $accounts->whereIn('type', ['ASSET', 'LIABILITY', 'EQUITY'])->map(fn ($a) => ['a' => $a, 'bal' => $a->balance(null, null, $to)]), 'profit' => $this->profit($accounts, null, $to, null)],
            'daybook' => ['entries' => JournalEntry::with('lines.account')->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)->orderBy('date')->orderBy('id')->get()],
            default => abort(404),
        };

        return view('acc.report', compact('report', 'from', 'to', 'loc', 'locations', 'data'));
    }

    protected function profit($accounts, $from, $to, $loc): float
    {
        return $accounts->where('type', 'INCOME')->sum(fn ($a) => $a->balance($loc, $from, $to)) - $accounts->where('type', 'EXPENSE')->sum(fn ($a) => $a->balance($loc, $from, $to));
    }

    protected function pnl($accounts, $from, $to, $loc, $locations): array
    {
        $inc = $accounts->where('type', 'INCOME')->map(fn ($a) => ['a' => $a, 'bal' => $a->balance($loc, $from, $to)]);
        $exp = $accounts->where('type', 'EXPENSE')->map(fn ($a) => ['a' => $a, 'bal' => $a->balance($loc, $from, $to)]);
        $byLoc = $locations->map(fn ($l) => ['l' => $l, 'income' => $accounts->where('type', 'INCOME')->sum(fn ($a) => $a->balance($l->id, $from, $to)), 'expense' => $accounts->where('type', 'EXPENSE')->sum(fn ($a) => $a->balance($l->id, $from, $to))]);

        return compact('inc', 'exp', 'byLoc');
    }

    public function closePeriod(Request $r)
    {
        $r->validate(['month' => 'required']);
        FiscalPeriod::updateOrCreate(['month' => $r->month], ['closed' => true, 'closed_by' => $r->user()->id, 'closed_at' => now()]);
        Audit::log('period.closed', null, null, ['month' => $r->month]);

        return back()->with('ok', 'Period '.$r->month.' closed — no more postings allowed');
    }
}
