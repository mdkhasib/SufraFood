@extends('layouts.app')
@section('title', __('Purchase board'))
@section('content')
@php($U = \App\Support\Ui::class)
@php($items = fn($r) => $r->lines->map(fn($l)=>($l->item?->name ?? $l->description).' '.$U::q($l->qty_requested).$l->item?->unit)->join(', '))
@if($pending->count() || $toAssign->count())
<div class="grid lg:grid-cols-2 gap-4 mb-5">
  <div class="card"><div class="px-4 py-2 border-b font-semibold">📝 Waiting approval ({{ $pending->count() }})</div>
    @foreach($pending as $r)<a href="{{ route('req.show', $r) }}" class="block px-4 py-2 border-b last:border-0 text-sm"><b>{{ $r->number }}</b> · {{ $r->fromLocation->name }} · {{ $items($r) }} · <b>{{ $U::tk($r->estimated_amount) }}</b></a>@endforeach</div>
  <div class="card"><div class="px-4 py-2 border-b font-semibold">🛒 Approved — assign a buyer ({{ $toAssign->count() }})</div>
    @foreach($toAssign as $r)<a href="{{ route('req.show', $r) }}" class="block px-4 py-2 border-b last:border-0 text-sm"><b>{{ $r->number }}</b> · {{ $items($r) }} · needed {{ $r->needed_by?->format('d M h:i A') }} → <span class="text-brand-700">assign</span></a>@endforeach</div>
</div>
@endif
<div class="card overflow-x-auto mb-5"><div class="px-4 py-2 border-b font-semibold">⏱ Active purchases</div><table class="t">
<tr><th>No.</th><th>Items</th><th>Buyer</th><th>Funding</th><th>Deadline</th><th>Amount</th><th>Status</th><th></th></tr>
@forelse($active as $pa)
<tr><td class="font-semibold">{{ $pa->number }}</td><td class="text-xs">{{ $items($pa->requisition) }}</td><td>{{ $pa->buyer->name }}</td><td>{{ \App\Services\PurchaseService::FUNDING[$pa->funding][0] }}</td>
<td class="{{ in_array($pa->status,['ASSIGNED','ACCEPTED']) && $pa->deadline_at->isPast() ? 'text-red-600 font-semibold' : '' }}">{{ $pa->deadline_at->format('d M h:i A') }}<div class="text-xs">{{ $pa->deadline_at->diffForHumans() }}</div></td>
<td>{{ $pa->actual_amount ? $U::tk($pa->actual_amount) : '' }} @if($pa->price_flag)<span class="badge bg-red-100 text-red-700">price!</span>@endif</td><td>{!! $U::badge($pa->status) !!}</td>
<td><a href="{{ route('pur.assignment', $pa) }}" class="btn btn-light btn-sm">Open</a></td></tr>
@empty <tr><td colspan="8" class="text-center text-slate-500 py-6">No active purchases</td></tr> @endforelse
</table></div>
<div class="card overflow-x-auto"><div class="px-4 py-2 border-b font-semibold">✓ Recently settled</div><table class="t">
<tr><th>No.</th><th>Items</th><th>Buyer</th><th>Funding</th><th>Vendor</th><th>Amount</th><th>Bought</th></tr>
@foreach($done as $pa)<tr><td>{{ $pa->number }}</td><td class="text-xs">{{ $items($pa->requisition) }}</td><td>{{ $pa->buyer->name }}</td><td>{{ \App\Services\PurchaseService::FUNDING[$pa->funding][0] }}</td><td>{{ $pa->vendor?->name }}</td><td>{{ $U::tk($pa->actual_amount) }}</td><td>{{ $pa->bought_at?->format('d M h:i A') }}</td></tr>@endforeach
</table></div>
@endsection
