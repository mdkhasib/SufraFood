@extends('layouts.app')
@section('title', __('Expenses'))
@section('content')
@php($U = \App\Support\Ui::class)
@if(auth()->user()->hasPerm('acc.manage'))
<form method="post" action="{{ route('acc.expenses.save') }}" class="card p-4 mb-4 grid sm:grid-cols-6 gap-3 items-end">@csrf
  <div><label class="lbl">Date</label><input type="date" name="date" value="{{ today()->toDateString() }}" class="inp"></div>
  <div><label class="lbl">Expense</label><select name="account_id" class="inp">@foreach($accounts as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select></div>
  <div><label class="lbl">Paid from</label><select name="money_account_id" class="inp">@foreach($money as $m)<option value="{{ $m->id }}">{{ $m->name }}</option>@endforeach</select></div>
  <div><label class="lbl">Location (cost centre)</label><select name="location_id" class="inp"><option value="">—</option>@foreach($locations as $l)<option value="{{ $l->id }}">{{ $l->name }}</option>@endforeach</select></div>
  <div><label class="lbl">Amount ৳</label><input name="amount" type="number" class="inp" required></div>
  <div><label class="lbl">Note</label><input name="note" class="inp"></div>
  <button class="btn btn-primary">Record expense</button>
</form>
@endif
<div class="card overflow-x-auto"><table class="t">
<tr><th>Date</th><th>Expense</th><th>Location</th><th>Paid from</th><th>Note</th><th class="text-right">Amount</th></tr>
@foreach($items as $x)<tr><td>{{ $x->date->format('d M Y') }}</td><td>{{ $x->account->name }}</td><td>{{ $x->location?->name }}</td><td>{{ $x->moneyAccount->name }}</td><td>{{ $x->note }}</td><td class="text-right font-semibold">{{ $U::tk($x->amount) }}</td></tr>@endforeach
</table></div>
<div class="mt-3">{{ $items->links() }}</div>
@endsection
