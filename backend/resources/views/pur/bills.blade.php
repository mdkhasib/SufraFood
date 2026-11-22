@extends('layouts.app')
@section('title', __('Vendor bills'))
@section('content')
@php($U = \App\Support\Ui::class)
<div class="flex flex-wrap gap-3 mb-4">@foreach($vendors as $x)<div class="card px-4 py-2 text-sm">{{ $x['v']->name }} · due <b>{{ $U::tk($x['due']) }}</b></div>@endforeach</div>
<div class="card overflow-x-auto"><table class="t">
<tr><th>Bill</th><th>Vendor</th><th>Date</th><th>Due</th><th>Amount</th><th>Paid</th><th>Status</th><th>Pay</th></tr>
@foreach($bills as $b)
<tr><td class="font-semibold">{{ $b->number }}</td><td>{{ $b->vendor->name }}</td><td>{{ $b->bill_date->format('d M') }}</td>
<td class="{{ $b->status!=='PAID' && $b->due_date->isPast() ? 'text-red-600 font-semibold' : '' }}">{{ $b->due_date->format('d M Y') }}</td><td>{{ $U::tk($b->amount) }}</td><td>{{ $U::tk($b->paid_amount) }}</td><td>{!! $U::badge($b->status) !!}</td>
<td>@if($b->status!=='PAID' && auth()->user()->hasPerm('acc.manage'))<form method="post" action="{{ route('pur.bills.pay', $b) }}" class="flex gap-1">@csrf<input name="amount" type="number" step="any" value="{{ $b->amount - $b->paid_amount }}" class="inp !py-1 !w-28"><select name="money_account_id" class="inp !py-1 !w-40">@foreach($money as $m)<option value="{{ $m->id }}">{{ $m->name }}</option>@endforeach</select><input name="reference" class="inp !py-1 !w-28" placeholder="ref / TxID"><button class="btn btn-green btn-sm">Pay</button></form>@endif</td></tr>
@endforeach
</table></div>
@endsection
