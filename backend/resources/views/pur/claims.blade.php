@extends('layouts.app')
@section('title', __('Refund claims'))
@section('content')
@php($U = \App\Support\Ui::class)
@if(auth()->user()->hasPerm('purchase.buy'))
<form method="post" enctype="multipart/form-data" action="{{ route('pur.claims.new') }}" class="card p-4 mb-4 grid sm:grid-cols-4 gap-3 items-end">@csrf
  <div><label class="lbl">Amount ৳ (paid from my pocket)</label><input name="amount" type="number" class="inp" required></div>
  <div><label class="lbl">What for</label><input name="description" class="inp" required></div>
  <div><label class="lbl">Receipt photo</label><input type="file" name="photo" accept="image/*" class="text-sm" required></div>
  <button class="btn btn-primary">Claim refund</button>
</form>
@endif
<div class="card overflow-x-auto"><table class="t">
<tr><th>Date</th><th>Employee</th><th>Description</th><th>Amount</th><th>Receipt</th><th>Status</th><th></th></tr>
@foreach($claims as $c)
<tr><td>{{ $c->created_at->format('d M') }}</td><td>{{ $c->user->name }}</td><td>{{ $c->description }}</td><td class="font-semibold">{{ $U::tk($c->amount) }}</td>
<td>@if($c->receipt_photo)<a href="{{ asset('storage/'.$c->receipt_photo) }}" target="_blank" class="text-brand-700">view</a>@endif</td>
<td>{!! $U::badge($c->status) !!} @if($c->paidFrom)<div class="text-xs">{{ $c->paidFrom->name }} {{ $c->paid_at?->format('d M') }}</div>@endif</td>
<td>@if($c->status==='APPROVED' && auth()->user()->hasPerm('acc.manage'))<form method="post" action="{{ route('pur.claims.pay', $c) }}" class="flex gap-1">@csrf<select name="money_account_id" class="inp !py-1 !w-44">@foreach($money as $m)<option value="{{ $m->id }}">{{ $m->name }}</option>@endforeach</select><button class="btn btn-green btn-sm">Pay</button></form>@endif</td></tr>
@endforeach
</table></div>
@endsection
