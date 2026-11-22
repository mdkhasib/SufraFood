@extends('layouts.app')
@section('title', __('Stock movements'))
@section('content')
@php($U = \App\Support\Ui::class)
@if($locations->count())<form class="mb-3"><select name="location_id" class="inp !w-60" onchange="this.form.submit()">@foreach($locations as $l)<option value="{{ $l->id }}" @selected($loc==$l->id)>{{ $l->name }}</option>@endforeach</select></form>@endif
<div class="card overflow-x-auto"><table class="t">
<tr><th>When</th><th>Item</th><th>Type</th><th class="text-right">Qty</th><th class="text-right">Unit cost</th><th>Ref</th><th>Reason</th><th>By</th></tr>
@foreach($moves as $m)
<tr><td class="whitespace-nowrap">{{ $m->created_at->format('d M H:i') }}</td><td>{{ $m->item->name }}</td><td>{!! $U::badge($m->type) !!}</td>
<td class="text-right font-semibold {{ $m->qty < 0 ? 'text-red-600' : 'text-green-700' }}">{{ $m->qty > 0 ? '+' : '' }}{{ $U::q($m->qty) }} {{ $m->item->unit }}</td><td class="text-right">{{ $U::tk($m->unit_cost, 2) }}</td>
<td class="text-xs">{{ $m->ref_type }} {{ $m->ref_id }}</td><td class="text-xs">{{ $m->reason }}</td><td class="text-xs">{{ $m->user?->name }}</td></tr>
@endforeach
</table></div>
<div class="mt-3">{{ $moves->links() }}</div>
@endsection
