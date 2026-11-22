@extends('layouts.app')
@section('title', ['pnl'=>'Profit & loss','tb'=>'Trial balance','bs'=>'Balance sheet','daybook'=>'Day book'][$report])
@section('content')
@php($U = \App\Support\Ui::class)
<form class="flex flex-wrap gap-2 mb-4 items-end">
  @foreach(['pnl'=>'P&L','tb'=>'Trial balance','bs'=>'Balance sheet','daybook'=>'Day book'] as $k=>$l)<a href="{{ route('acc.report', $k) }}" class="btn btn-sm {{ $report===$k ? 'btn-dark' : 'btn-light' }}">{{ $l }}</a>@endforeach
  @if(in_array($report,['pnl','daybook']))<div><label class="lbl">From</label><input type="date" name="from" value="{{ $from }}" class="inp"></div>@endif
  <div><label class="lbl">To</label><input type="date" name="to" value="{{ $to }}" class="inp"></div>
  @if($report==='pnl')<div><label class="lbl">Location</label><select name="location_id" class="inp"><option value="">Whole group</option>@foreach($locations as $l)<option value="{{ $l->id }}" @selected($loc==$l->id)>{{ $l->name }}</option>@endforeach</select></div>@endif
  <button class="btn btn-light">Run</button><button type="button" onclick="window.print()" class="btn btn-light">🖨</button>
</form>
@if($report==='pnl')
<div class="grid lg:grid-cols-2 gap-4">
  <div class="card p-4"><table class="t">
    <tr><th colspan="2">Income</th></tr>@foreach($data['inc'] as $x) @if($x['bal'])<tr><td>{{ $x['a']->name }}</td><td class="text-right">{{ $U::tk($x['bal'],2) }}</td></tr>@endif @endforeach
    <tr class="font-bold"><td>Total income</td><td class="text-right">{{ $U::tk($data['inc']->sum('bal'),2) }}</td></tr>
    <tr><th colspan="2">Expenses</th></tr>@foreach($data['exp'] as $x) @if($x['bal'])<tr><td>{{ $x['a']->name }}</td><td class="text-right">{{ $U::tk($x['bal'],2) }}</td></tr>@endif @endforeach
    <tr class="font-bold"><td>Total expenses</td><td class="text-right">{{ $U::tk($data['exp']->sum('bal'),2) }}</td></tr>
    @php($np = $data['inc']->sum('bal') - $data['exp']->sum('bal'))
    <tr class="text-lg font-bold {{ $np >= 0 ? 'text-green-700' : 'text-red-600' }} bg-orange-50"><td>Net profit</td><td class="text-right">{{ $U::tk($np,2) }}</td></tr>
  </table></div>
  <div class="card p-4"><div class="font-semibold mb-2">By location (cost centre)</div><table class="t">
    <tr><th>Location</th><th class="text-right">Income</th><th class="text-right">Expense</th><th class="text-right">Profit</th></tr>
    @foreach($data['byLoc'] as $x) @if($x['income'] || $x['expense'])<tr><td>{{ $x['l']->name }}</td><td class="text-right">{{ number_format($x['income']) }}</td><td class="text-right">{{ number_format($x['expense']) }}</td><td class="text-right font-semibold {{ $x['income']-$x['expense'] >= 0 ? 'text-green-700' : 'text-red-600' }}">{{ number_format($x['income']-$x['expense']) }}</td></tr>@endif @endforeach
  </table></div>
</div>
@elseif($report==='tb')
<div class="card overflow-x-auto"><table class="t"><tr><th>Code</th><th>Account</th><th class="text-right">Debit</th><th class="text-right">Credit</th></tr>
@foreach($data['rows'] as $x)<tr><td>{{ $x['a']->code }}</td><td>{{ $x['a']->name }}</td><td class="text-right">{{ number_format($x['dr'],2) }}</td><td class="text-right">{{ number_format($x['cr'],2) }}</td></tr>@endforeach
<tr class="font-bold bg-orange-50"><td colspan="2">Total {{ abs($data['rows']->sum('dr') - $data['rows']->sum('cr')) < 0.01 ? '✓ balanced' : '✗ NOT balanced' }}</td><td class="text-right">{{ number_format($data['rows']->sum('dr'),2) }}</td><td class="text-right">{{ number_format($data['rows']->sum('cr'),2) }}</td></tr></table></div>
@elseif($report==='bs')
@php($g = $data['rows']->groupBy(fn($x)=>$x['a']->type))
<div class="grid lg:grid-cols-2 gap-4">
  <div class="card p-4"><table class="t"><tr><th colspan="2">Assets</th></tr>@foreach($g['ASSET'] ?? [] as $x)<tr><td>{{ $x['a']->name }}</td><td class="text-right">{{ number_format($x['bal'],2) }}</td></tr>@endforeach
    <tr class="font-bold"><td>Total assets</td><td class="text-right">{{ number_format(collect($g['ASSET'] ?? [])->sum('bal'),2) }}</td></tr></table></div>
  <div class="card p-4"><table class="t"><tr><th colspan="2">Liabilities</th></tr>@foreach($g['LIABILITY'] ?? [] as $x)<tr><td>{{ $x['a']->name }}</td><td class="text-right">{{ number_format($x['bal'],2) }}</td></tr>@endforeach
    <tr><th colspan="2">Equity</th></tr>@foreach($g['EQUITY'] ?? [] as $x)<tr><td>{{ $x['a']->name }}</td><td class="text-right">{{ number_format($x['bal'],2) }}</td></tr>@endforeach
    <tr><td>Retained profit (to date)</td><td class="text-right">{{ number_format($data['profit'],2) }}</td></tr>
    <tr class="font-bold"><td>Total liabilities + equity</td><td class="text-right">{{ number_format(collect($g['LIABILITY'] ?? [])->sum('bal') + collect($g['EQUITY'] ?? [])->sum('bal') + $data['profit'],2) }}</td></tr></table></div>
</div>
@else
<div class="card overflow-x-auto"><table class="t"><tr><th>Date</th><th>No.</th><th>Memo</th><th>Account</th><th class="text-right">Dr</th><th class="text-right">Cr</th></tr>
@foreach($data['entries'] as $e) @foreach($e->lines as $i=>$l)<tr><td>{{ $i===0 ? $e->date->format('d M') : '' }}</td><td>{{ $i===0 ? $e->number : '' }}</td><td class="text-xs">{{ $i===0 ? $e->memo : '' }}</td><td>{{ $l->account->name }}</td><td class="text-right">{{ $l->debit ? number_format($l->debit,2) : '' }}</td><td class="text-right">{{ $l->credit ? number_format($l->credit,2) : '' }}</td></tr>@endforeach @endforeach
</table></div>
@endif
@endsection
