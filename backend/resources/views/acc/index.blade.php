@extends('layouts.app')
@section('title', __('Accounting'))
@section('content')
@php($U = \App\Support\Ui::class)
@php($canManage = auth()->user()->hasPerm('acc.manage'))
<div class="grid lg:grid-cols-3 gap-4 mb-5">
  <div class="card overflow-x-auto lg:col-span-2"><div class="px-4 py-2 border-b font-semibold">💰 Cash · Bank · MFS · Petty · Drawers</div><table class="t">
  <tr><th>Account</th><th>Type</th><th>Location / custodian</th><th class="text-right">Balance</th></tr>
  @foreach($money as $x)<tr><td>{{ $x['m']->name }}</td><td>{!! $U::badge($x['m']->type) !!}</td><td class="text-xs">{{ $x['m']->location?->name }} {{ $x['m']->custodian ? '· '.$x['m']->custodian->name : '' }}</td><td class="text-right font-semibold {{ $x['bal'] < 0 ? 'text-red-600' : '' }}">{{ $U::tk($x['bal'], 2) }}</td></tr>@endforeach
  <tr class="bg-orange-50 font-bold"><td colspan="3">Total</td><td class="text-right">{{ $U::tk($money->sum('bal'), 2) }}</td></tr>
  </table></div>
  <div class="space-y-4">
    <div class="card p-4"><div class="text-xs text-slate-500">Inventory value (all locations, last cost)</div><div class="text-2xl font-bold">{{ $U::tk($stockValue) }}</div></div>
    <div class="card p-4 space-y-1 text-sm"><div class="font-semibold mb-1">Reports</div>
      <a class="block text-brand-700" href="{{ route('acc.report','pnl') }}">📈 Profit & loss (by branch)</a><a class="block text-brand-700" href="{{ route('acc.report','tb') }}">⚖ Trial balance</a>
      <a class="block text-brand-700" href="{{ route('acc.report','bs') }}">🏦 Balance sheet</a><a class="block text-brand-700" href="{{ route('acc.report','daybook') }}">📒 Day book</a></div>
    @if($canManage)
    <form method="post" action="{{ route('acc.transfer') }}" class="card p-4 space-y-2">@csrf<div class="font-semibold">⇄ Transfer between accounts</div>
      <select name="from" class="inp">@foreach($money as $x)<option value="{{ $x['m']->id }}">From: {{ $x['m']->name }}</option>@endforeach</select>
      <select name="to" class="inp">@foreach($money as $x)<option value="{{ $x['m']->id }}">To: {{ $x['m']->name }}</option>@endforeach</select>
      <input name="amount" type="number" class="inp" placeholder="amount ৳" required><input name="reference" class="inp" placeholder="ref (e.g. drawer deposit)"><button class="btn btn-dark w-full justify-center">Post transfer</button></form>
    <form method="post" action="{{ route('acc.close') }}" class="card p-4 flex gap-2 items-end" onsubmit="return confirm('Lock this month?')">@csrf<div class="flex-1"><label class="lbl">Close period</label><input type="month" name="month" value="{{ now()->subMonth()->format('Y-m') }}" class="inp"></div><button class="btn btn-light">Lock</button></form>
    @endif
    @if($periods->count())<div class="text-xs text-slate-500">Closed: {{ $periods->where('closed',true)->pluck('month')->join(', ') }}</div>@endif
  </div>
</div>
<div class="card overflow-x-auto"><div class="px-4 py-2 border-b font-semibold">Chart of accounts</div><table class="t">
<tr><th>Code</th><th>Account</th><th>Type</th><th class="text-right">Balance</th><th></th></tr>
@foreach($accounts as $x)<tr><td>{{ $x['a']->code }}</td><td>{{ $x['a']->name }}</td><td>{{ $x['a']->type }}</td><td class="text-right font-semibold">{{ $U::tk($x['bal'], 2) }}</td><td><a class="text-brand-700 text-xs" href="{{ route('acc.journal', ['account_id'=>$x['a']->id]) }}">ledger</a></td></tr>@endforeach
</table></div>
@endsection
