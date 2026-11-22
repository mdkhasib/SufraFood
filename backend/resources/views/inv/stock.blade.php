@extends('layouts.app')
@section('title', __('Stock'))
@section('content')
@php($U = \App\Support\Ui::class)
@php($me = auth()->user())
<form class="flex flex-wrap gap-2 mb-4 items-end">
  @if($locations->count())<div><label class="lbl">Location</label><select name="location_id" class="inp" onchange="this.form.submit()">@foreach($locations as $l)<option value="{{ $l->id }}" @selected($loc==$l->id)>{{ $l->name }}</option>@endforeach</select></div>@endif
  <div><label class="lbl">Type</label><select name="type" class="inp" onchange="this.form.submit()"><option value="">All</option>@foreach(['RAW','SEMI','CONSUMABLE','ASSET'] as $t)<option @selected(request('type')===$t)>{{ $t }}</option>@endforeach</select></div>
  <div><label class="lbl">Search</label><input name="q" value="{{ request('q') }}" class="inp"></div>
  <a href="{{ route('inv.movements', ['location_id'=>$loc]) }}" class="btn btn-light">Movements ledger</a>
  <div class="ml-auto card px-4 py-2 text-sm">Stock value: <b>{{ $U::tk($value) }}</b></div>
</form>
<div class="grid lg:grid-cols-3 gap-4">
<div class="card overflow-x-auto lg:col-span-2"><table class="t">
<tr><th>Item</th><th>Type</th><th class="text-right">Qty</th><th class="text-right">Reorder</th><th class="text-right">Value</th></tr>
@foreach($stocks as $s)
<tr class="{{ $s->item->reorder_level > 0 && $s->qty < $s->item->reorder_level ? 'bg-red-50' : '' }}"><td><b>{{ $s->item->displayName() }}</b> <span class="text-xs text-slate-400">{{ $s->item->code }}</span></td><td>{{ $s->item->type }}</td>
<td class="text-right font-semibold {{ $s->qty < 0 ? 'text-red-600' : '' }}">{{ $U::q($s->qty) }} {{ $s->item->unit }}</td><td class="text-right text-xs">{{ $s->item->reorder_level ? $U::q($s->item->reorder_level) : '' }} @if($s->item->reorder_level > 0 && $s->qty < $s->item->reorder_level)<span class="badge bg-red-100 text-red-700">LOW</span>@endif</td>
<td class="text-right">{{ $U::tk($s->qty * $s->item->last_price) }}</td></tr>
@endforeach
</table></div>
<div class="space-y-4">
  @if($me->hasPerm('inventory.wastage'))
  <form method="post" action="{{ route('inv.wastage') }}" class="card p-4 space-y-2">@csrf<input type="hidden" name="location_id" value="{{ $loc }}">
    <div class="font-semibold">🗑 Log wastage</div>
    <select name="item_id" class="inp">@foreach($items as $i)<option value="{{ $i->id }}">{{ $i->name }} ({{ $i->unit }})</option>@endforeach</select>
    <input type="number" step="any" name="qty" class="inp" placeholder="quantity" required>
    <select name="reason" class="inp"><option>Spoiled</option><option>Burnt</option><option>Expired</option><option>Returned by customer</option><option>Staff meal</option><option>Damaged</option></select>
    <button class="btn btn-red w-full justify-center">Log wastage</button>
  </form>
  @endif
  @if($me->hasPerm('inventory.count'))
  <form method="post" action="{{ route('inv.count') }}" class="card p-4 space-y-2">@csrf<input type="hidden" name="location_id" value="{{ $loc }}">
    <div class="font-semibold">🔢 Stock count</div>
    <select name="item_id" class="inp">@foreach($items as $i)<option value="{{ $i->id }}">{{ $i->name }} ({{ $i->unit }})</option>@endforeach</select>
    <input type="number" step="any" name="counted_qty" class="inp" placeholder="counted quantity" required>
    <button class="btn btn-dark w-full justify-center">Save count (adjusts stock)</button>
  </form>
  @endif
</div>
</div>
@endsection
