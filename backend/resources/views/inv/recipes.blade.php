@extends('layouts.app')
@section('title', __('Recipes'))
@section('content')
@php($U = \App\Support\Ui::class)
@php($canEdit = auth()->user()->hasAnyPerm(['production.manage','inventory.manage']))
<div class="grid lg:grid-cols-2 gap-4">
@foreach($items as $it)
<form method="post" action="{{ route('inv.recipes.save', $it) }}" class="card p-4" x-data="{lines: @js($it->recipe?->lines->map(fn($l)=>['item_id'=>$l->item_id,'qty'=>$l->qty])->values() ?? []), edit:false}">@csrf
  <div class="flex items-center justify-between mb-2">
    <div><b>{{ $it->name }}</b> <span class="text-xs text-slate-400">{{ $it->name_bn }} · {{ $it->type }}</span>
      <div class="text-xs text-slate-500">Cost {{ $U::tk($it->last_price, 2) }} / {{ $it->unit }} @if($it->sale_price) · sells {{ $U::tk($it->sale_price) }} · food cost {{ round($it->last_price / max(1,$it->sale_price) * 100, 1) }}% @endif</div></div>
    @if($canEdit)<button type="button" class="btn btn-light btn-sm" @click="edit=!edit">✎</button>@endif
  </div>
  <div class="text-sm" x-show="!edit">Yield {{ $U::q($it->recipe?->yield_qty ?? 1) }} {{ $it->unit }}: @foreach($it->recipe?->lines ?? [] as $l)<span class="badge bg-slate-100 mr-1 mb-1">{{ $l->item->name }} {{ $U::q($l->qty) }}{{ $l->item->unit }}</span>@endforeach</div>
  <div x-show="edit" x-cloak class="space-y-2 mt-2">
    <div class="flex items-center gap-2 text-sm">Yield <input name="yield_qty" type="number" step="any" value="{{ $it->recipe?->yield_qty ?? 1 }}" class="inp !w-24"> {{ $it->unit }}</div>
    <template x-for="(l,i) in lines" :key="i"><div class="flex gap-2"><select class="inp" :name="'lines['+i+'][item_id]'" x-model="l.item_id">@foreach($ingredients as $g)<option value="{{ $g->id }}">{{ $g->name }} ({{ $g->unit }})</option>@endforeach</select>
      <input class="inp !w-24" type="number" step="any" :name="'lines['+i+'][qty]'" x-model="l.qty"><button type="button" class="btn btn-light btn-sm" @click="lines.splice(i,1)">✕</button></div></template>
    <div class="flex gap-2"><button type="button" class="btn btn-light btn-sm" @click="lines.push({item_id:'{{ $ingredients->first()?->id }}',qty:1})">+ ingredient</button><button class="btn btn-primary btn-sm">Save recipe</button></div>
  </div>
</form>
@endforeach
</div>
@endsection
