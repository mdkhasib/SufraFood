@extends('layouts.app')
@section('title', __('Buyer wallets'))
@section('content')
@php($U = \App\Support\Ui::class)
@php($can = auth()->user()->hasPerm('purchase.advance'))
<div class="grid lg:grid-cols-3 gap-4">
@foreach($buyers as $b) @php($w = $b['w'])
<div class="card p-4">
  <div class="font-semibold text-lg">{{ $b['user']->name }}</div><div class="text-xs text-slate-500 mb-3">limit {{ $w['advance_limit'] ? $U::tk($w['advance_limit']) : '—' }}</div>
  <div class="grid grid-cols-2 gap-2 text-sm">
    <div class="rounded bg-slate-50 p-2">Advance held<div class="font-bold">{{ $U::tk($w['advance_held']) }}</div></div>
    <div class="rounded bg-slate-50 p-2">Spent<div class="font-bold">{{ $U::tk($w['spent'] + $w['spent_pending']) }}</div></div>
    <div class="rounded bg-orange-50 p-2">Balance to return<div class="font-bold">{{ $U::tk($w['balance']) }}</div></div>
    <div class="rounded bg-purple-50 p-2">Company owes<div class="font-bold">{{ $U::tk($w['company_owes']) }}</div></div>
  </div>
  @if($w['receipts_pending'])<div class="text-xs text-amber-700 mt-2">{{ $w['receipts_pending'] }} purchase(s) waiting goods-received</div>@endif
  <div class="mt-3 text-xs space-y-1">@foreach($b['tx'] as $t)<div class="flex justify-between"><span>{!! $U::badge($t->type) !!} {{ $t->created_at->format('d M') }} {{ \Illuminate\Support\Str::limit($t->note, 26) }}</span><b>{{ $U::tk($t->amount) }}</b></div>@endforeach</div>
  @if($can)
  <form method="post" action="{{ route('pur.wallet.action', [$b['user'], 'issue']) }}" class="mt-3 flex gap-1" x-data="{a:'issue'}" :action="'{{ url('/wallets/'.$b['user']->id) }}/'+a">@csrf
    <select x-model="a" class="inp !w-24 !py-1 text-xs"><option value="issue">Issue</option><option value="return">Return</option></select>
    <input name="amount" type="number" class="inp !py-1" placeholder="৳" required>
    <select name="money_account_id" class="inp !py-1 text-xs">@foreach($money as $m)<option value="{{ $m->id }}">{{ $m->name }}</option>@endforeach</select>
    <button class="btn btn-dark btn-sm">OK</button></form>
  @endif
</div>
@endforeach
</div>
@endsection
