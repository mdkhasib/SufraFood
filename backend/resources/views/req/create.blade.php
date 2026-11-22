@extends('layouts.app')
@section('title', __('New request'))
@section('content')
@php($itemTypes = ['FOOD_ORDER'=>['SEMI'],'RAW_MATERIAL'=>['RAW'],'SUPPLIES'=>['CONSUMABLE','ASSET'],'EQUIPMENT'=>['ASSET','CONSUMABLE'],'REPAIR'=>[],'PURCHASE'=>['RAW','CONSUMABLE','ASSET'],'TRANSFER'=>['RAW','SEMI','CONSUMABLE']])
<form method="post" action="{{ route('req.store') }}" class="card p-5 max-w-4xl" x-data="reqForm()">@csrf
  <div class="grid sm:grid-cols-3 gap-4">
    <div><label class="lbl">Type</label><select name="type" class="inp" x-model="type">@foreach($types as $k=>$t)<option value="{{ $k }}">{{ $t[0] }}</option>@endforeach</select></div>
    <div><label class="lbl">Needed by</label><input type="datetime-local" name="needed_by" class="inp" value="{{ now()->addDay()->setTime(11,0)->format('Y-m-d\TH:i') }}" required></div>
    <div><label class="lbl">Priority</label><select name="priority" class="inp"><option>NORMAL</option><option>URGENT</option></select></div>
    @if($locations->count())<div><label class="lbl">Request for location</label><select name="from_location_id" class="inp">@foreach($locations as $l)<option value="{{ $l->id }}" @selected($l->id==auth()->user()->location_id)>{{ $l->name }}</option>@endforeach</select></div>@endif
    <div x-show="type==='TRANSFER'"><label class="lbl">From branch</label><select name="to_location_id" class="inp">@foreach($branches as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach</select></div>
    <div class="sm:col-span-3"><label class="lbl">Note</label><input name="notes" class="inp"></div>
  </div>
  <p class="text-xs text-slate-500 mt-2" x-show="type==='FOOD_ORDER'">Orders for tomorrow after {{ \App\Support\Features::setting('orders.cutoff_time') }} today are marked URGENT automatically.</p>
  <div class="mt-5">
    <div class="font-semibold mb-2" x-text="type==='REPAIR' ? 'What needs repair' : 'Items'"></div>
    <template x-for="(l,i) in lines" :key="i">
      <div class="grid grid-cols-12 gap-2 mb-2">
        <template x-if="type!=='REPAIR'"><select class="inp col-span-7" :name="'lines['+i+'][item_id]'" x-model="l.item_id"><option value="">— item —</option>
          <template x-for="it in items.filter(x=>allowed[type].includes(x.type))" :key="it.id"><option :value="it.id" x-text="it.name+' ('+it.unit+') · ৳'+it.last_price"></option></template></select></template>
        <template x-if="type==='REPAIR'"><input class="inp col-span-7" :name="'lines['+i+'][description]'" placeholder="describe the problem"></template>
        <input class="inp col-span-3" type="number" step="any" min="0" :name="'lines['+i+'][qty]'" x-model="l.qty" placeholder="qty">
        <button type="button" class="btn btn-light col-span-2" @click="lines.splice(i,1)">✕</button>
      </div>
    </template>
    <button type="button" class="btn btn-light btn-sm" @click="lines.push({item_id:'',qty:''})">+ line</button>
    <div class="text-sm text-slate-600 mt-3">Estimated: <b x-text="'৳'+total().toLocaleString()"></b> <span class="text-xs">(decides the approval chain)</span></div>
  </div>
  <button class="btn btn-primary mt-5">{{ __('Send request') }}</button>
</form>
@push('scripts')
<script>
function reqForm(){ return { type: @json($types->keys()->first()), items: @json($items), allowed: @json($itemTypes), lines:[{item_id:'',qty:''},{item_id:'',qty:''}],
  total(){ return this.lines.reduce((s,l)=>{ const it=this.items.find(x=>x.id==l.item_id); return s + (it ? it.last_price*(+l.qty||0) : 0) },0) } } }
</script>
@endpush
@endsection
