@extends('layouts.app')
@section('title', $e->number)
@section('content')
<div class="card p-4 mb-4"><div class="text-lg font-bold">{{ $e->number }} · {{ $e->date->format('d M Y') }}</div><div class="text-sm">{{ $e->memo }}</div>
<div class="text-xs text-slate-500">Source: {{ $e->source_type }} #{{ $e->source_id }} · posted by {{ $e->poster?->name ?? 'system' }} · {{ $e->created_at->format('d M Y h:i A') }}</div></div>
<div class="card overflow-x-auto"><table class="t">
<tr><th>Account</th><th>Money account</th><th>Location</th><th>Memo</th><th class="text-right">Debit</th><th class="text-right">Credit</th></tr>
@foreach($e->lines as $l)<tr><td>{{ $l->account->code }} {{ $l->account->name }}</td><td>{{ $l->moneyAccount?->name }}</td><td>{{ $l->location?->name }}</td><td class="text-xs">{{ $l->memo }}</td><td class="text-right">{{ $l->debit ? number_format($l->debit,2) : '' }}</td><td class="text-right">{{ $l->credit ? number_format($l->credit,2) : '' }}</td></tr>@endforeach
<tr class="font-bold bg-slate-50"><td colspan="4">Total</td><td class="text-right">{{ number_format($e->lines->sum('debit'),2) }}</td><td class="text-right">{{ number_format($e->lines->sum('credit'),2) }}</td></tr>
</table></div>
@endsection
