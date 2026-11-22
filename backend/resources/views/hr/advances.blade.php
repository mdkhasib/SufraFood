@extends('layouts.app')
@section('title', __('Salary advances'))
@section('content')
@php($U = \App\Support\Ui::class)
@if(auth()->user()->hasPerm('payroll.advance_request'))
<form method="post" action="{{ route('advances.request') }}" class="card p-4 mb-4 grid sm:grid-cols-4 gap-3 items-end">@csrf
  <div><label class="lbl">Amount ৳</label><input type="number" name="amount" class="inp" required></div>
  <div><label class="lbl">Repay over (months)</label><input type="number" name="months" value="3" min="1" max="12" class="inp"></div>
  <div><label class="lbl">Reason</label><input name="reason" class="inp"></div>
  <button class="btn btn-primary">{{ __('Request advance') }}</button>
</form>
@endif
<div class="card overflow-x-auto mb-4"><div class="px-4 py-2 font-semibold border-b">{{ __('Mine') }}</div><table class="t">
<tr><th>Date</th><th>Amount</th><th>Instalment</th><th>Remaining</th><th>Status</th></tr>
@foreach($mine as $a)<tr><td>{{ $a->created_at->format('d M Y') }}</td><td>{{ $U::tk($a->amount) }}</td><td>{{ $U::tk($a->installment) }}</td><td>{{ $U::tk($a->remaining) }}</td><td>{!! $U::badge($a->status) !!}</td></tr>@endforeach
</table></div>
@if($all->count())
<div class="card overflow-x-auto"><div class="px-4 py-2 font-semibold border-b">{{ __('All advances') }}</div><table class="t">
<tr><th>Employee</th><th>Amount</th><th>Instalment</th><th>Remaining</th><th>Status</th><th></th></tr>
@foreach($all as $a)<tr><td>{{ $a->user->name }}</td><td>{{ $U::tk($a->amount) }}</td><td>{{ $U::tk($a->installment) }}</td><td>{{ $U::tk($a->remaining) }}</td><td>{!! $U::badge($a->status) !!}</td>
<td>@if($a->status==='APPROVED' && auth()->user()->hasPerm('acc.manage'))<form method="post" action="{{ route('advances.pay', $a) }}" class="flex gap-1">@csrf<select name="money_account_id" class="inp !w-40 !py-1">@foreach($money as $m)<option value="{{ $m->id }}">{{ $m->name }}</option>@endforeach</select><button class="btn btn-green btn-sm">Pay</button></form>@endif</td></tr>@endforeach
</table></div>
@endif
@endsection
