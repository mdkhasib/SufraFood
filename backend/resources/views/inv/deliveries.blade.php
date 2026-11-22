@extends('layouts.app')
@section('title', __('Deliveries'))
@section('content')
@php($U = \App\Support\Ui::class)
<p class="text-sm text-slate-500 mb-3">Drivers pick up and deliver from the mobile app; the receiving location confirms quantities on the request.</p>
<div class="card overflow-x-auto"><table class="t">
<tr><th>No.</th><th>Route</th><th>Request</th><th>Items</th><th>Driver</th><th>Status</th><th>Times</th></tr>
@foreach($items as $d)
<tr><td class="font-semibold">{{ $d->number }}</td><td>{{ $d->fromLocation->name }} → {{ $d->toLocation->name }}</td><td><a class="text-brand-700" href="{{ route('req.show', $d->requisition_id) }}">{{ $d->requisition->number }}</a></td>
<td class="text-xs">{{ $d->requisition->lines->map(fn($l)=>($l->item?->name ?? $l->description).' '.$U::q($l->qty_sent))->join(', ') }}</td>
<td>{{ $d->driver?->name ?? 'internal' }} <div class="text-xs text-slate-400">{{ $d->vehicle }}</div></td><td>{!! $U::badge($d->status) !!}</td>
<td class="text-xs">out {{ $d->created_at->format('d M h:i A') }}@if($d->delivered_at)<br>delivered {{ $d->delivered_at->format('h:i A') }}@endif</td></tr>
@endforeach
</table></div>
<div class="mt-3">{{ $items->links() }}</div>
@endsection
