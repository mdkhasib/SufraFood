@extends('layouts.app')
@section('title', $e->number)
@section('content')
@php($U = \App\Support\Ui::class)
<div class="card p-4 mb-4 flex flex-wrap gap-6 items-center">
  <div><div class="text-xl font-bold">{{ $e->title }} {!! $U::badge($e->status) !!}</div><div class="text-sm text-slate-500">{{ $e->client_name }} · {{ $e->client_phone }}</div></div>
  <div><div class="text-xs text-slate-500">When</div><b>{{ $e->event_at->format('D d M Y, h:i A') }}</b></div>
  <div><div class="text-xs text-slate-500">Venue</div><b>{{ $e->venue }}</b>@if($e->lat)<div class="text-xs text-slate-400">GPS area {{ $e->radius_m }} m</div>@endif</div>
  <div><div class="text-xs text-slate-500">{{ $e->guests }} guests × {{ $U::tk($e->price_per_head) }}</div><b>{{ $U::tk($e->total) }}</b></div>
  <div><div class="text-xs text-slate-500">Paid / due</div><b>{{ $U::tk($e->paid) }}</b> / <span class="text-red-600">{{ $U::tk($e->total - $e->paid) }}</span></div>
  <div class="ml-auto flex gap-2">
    @if($e->status==='INQUIRY')<form method="post" action="{{ route('cat.action', [$e,'confirm']) }}" onsubmit="return confirm('Confirm event? This creates food orders, equipment request, staff tasks and the invoice.')">@csrf<button class="btn btn-primary">✓ Confirm event</button></form>@endif
    @if($e->status==='CONFIRMED')<form method="post" action="{{ route('cat.action', [$e,'delivered']) }}">@csrf<button class="btn btn-green">Mark delivered</button></form>@endif
    @if($e->status==='DELIVERED')<form method="post" action="{{ route('cat.action', [$e,'closed']) }}">@csrf<button class="btn btn-dark">Close</button></form>@endif
    @if(in_array($e->status,['INQUIRY','CONFIRMED']))<form method="post" action="{{ route('cat.action', [$e,'cancelled']) }}" onsubmit="return confirm('Cancel?')">@csrf<button class="btn btn-light">Cancel</button></form>@endif
  </div>
</div>
<div class="grid lg:grid-cols-3 gap-4">
  <div class="card p-4"><div class="font-semibold mb-2">Menu & equipment</div>
    @foreach($e->items as $i)<div class="flex justify-between text-sm py-1 border-b last:border-0"><span>{{ $i->kind==='FOOD' ? '🍛' : '🍽' }} {{ $i->item->name }}</span><b>{{ $U::q($i->qty) }} {{ $i->item->unit }}</b></div>@endforeach</div>
  <div class="card p-4"><div class="font-semibold mb-2">Staff ({{ $e->staff->count() }})</div>
    @foreach($e->staff as $s)<div class="text-sm py-0.5">{{ $s->name }} <span class="text-xs text-slate-400">{{ $s->pivot->duty }}</span></div>@endforeach
    @if($e->requisitions->count())<div class="font-semibold mt-4 mb-1">Requests created</div>@foreach($e->requisitions as $r)<a href="{{ route('req.show',$r) }}" class="block text-sm text-brand-700">{{ $r->number }} {{ $r->typeLabel() }} {!! $U::badge($r->status) !!}</a>@endforeach @endif
  </div>
  <div class="card p-4"><div class="font-semibold mb-2">Payments</div>
    @foreach($e->payments as $p)<div class="flex justify-between text-sm py-1 border-b"><span>{{ $p->date->format('d M') }} · {{ $p->moneyAccount->name }} {{ $p->reference }}</span><b>{{ $U::tk($p->amount) }}</b></div>@endforeach
    @if($e->paid < $e->total && $e->status!=='CANCELLED')
    <form method="post" action="{{ route('cat.action', [$e,'payment']) }}" class="mt-3 space-y-2">@csrf
      <input name="amount" type="number" class="inp" placeholder="amount ৳" value="{{ $e->total - $e->paid }}">
      <select name="money_account_id" class="inp">@foreach($money as $m)<option value="{{ $m->id }}">{{ $m->name }}</option>@endforeach</select>
      <input name="reference" class="inp" placeholder="reference / TxID"><button class="btn btn-green w-full justify-center">Record payment</button></form>
    @endif
  </div>
</div>
@endsection
