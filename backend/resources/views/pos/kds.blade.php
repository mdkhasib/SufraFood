@extends('layouts.app')
@section('title', __('Kitchen display'))
@section('content')
<div x-data="kds()" x-init="load(); setInterval(()=>load(), 8000)">
  <div class="text-sm text-slate-500 mb-3">Auto-refreshes every 8 seconds · tap an item when it is ready</div>
  <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3">
    <template x-for="o in orders" :key="o.id">
      <div class="card p-3" :class="o.age > 15 ? 'border-red-400 border-2' : ''">
        <div class="flex justify-between font-bold"><span x-text="o.table ? 'Table '+o.table : o.type.replace('_',' ')"></span><span x-text="o.age+' min'" :class="o.age>15 ? 'text-red-600' : 'text-slate-500'"></span></div>
        <div class="text-xs text-slate-400 mb-1" x-text="o.number+' · '+o.at"></div>
        <div x-show="o.qr" class="text-xs rounded bg-purple-50 text-purple-800 px-2 py-1 mb-2 font-semibold">📱 QR order from the table</div>
        <div x-show="o.delivery" class="text-xs rounded bg-orange-50 text-orange-800 px-2 py-1 mb-2"><b x-text="(o.online ? '🌐 ' : '')+'🛵 HOME DELIVERY'"></b> · <span x-text="o.delivery"></span><span x-show="o.rider" x-text="' · rider '+o.rider"></span></div>
        <template x-for="l in o.lines" :key="l.id"><button class="w-full text-left flex justify-between py-2 px-2 rounded hover:bg-green-50 border-b" @click="ready(l.id)">
          <span><b x-text="l.qty+'×'"></b> <span x-text="l.name"></span> <span class="text-xs text-slate-400" x-text="l.name_bn"></span></span><span class="text-green-600">✓</span></button></template>
      </div>
    </template>
    <div x-show="!orders.length" class="text-slate-400">No pending kitchen orders 👨‍🍳</div>
  </div>
</div>
@push('scripts')
<script>
function kds(){ return { orders:[],
  async load(){ const r=await fetch('{{ route('pos.kds.feed') }}',{headers:{Accept:'application/json'}}); this.orders=await r.json() },
  async ready(id){ await fetch('{{ url('/kds') }}/'+id+'/ready',{method:'POST',headers:{'X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content,Accept:'application/json'}}); this.load() } } }
</script>
@endpush
@endsection
