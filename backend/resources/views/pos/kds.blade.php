@extends('layouts.app')
@section('title', __('Kitchen display'))
@section('content')
<div x-data="kds()" x-init="start()">
  <div class="flex flex-wrap items-center gap-3 mb-3">
    @if($branches->count())
      <form method="get" class="flex items-center gap-2">
        <label class="text-sm font-semibold">{{ __('Kitchen') }}</label>
        <select name="location" class="inp !w-56" onchange="this.form.submit()">
          @foreach($branches as $b)<option value="{{ $b->id }}" @selected($loc?->id === $b->id)>{{ $b->name }}</option>@endforeach
        </select>
      </form>
    @else
      <div class="text-lg font-bold">🍳 {{ $loc?->name ?? __('No branch') }}</div>
    @endif
    <div class="text-sm text-slate-500">{{ __('Refreshes every 8 seconds · tap an item when it is ready') }}</div>
    <div class="ml-auto flex items-center gap-2 text-sm">
      <span class="badge bg-slate-100 text-slate-700" x-text="orders.length+' {{ __('orders') }} · '+items()+' {{ __('items') }}'"></span>
      <label class="flex items-center gap-1"><input type="checkbox" x-model="sound"> 🔔 {{ __('Sound') }}</label>
      <span x-show="err" x-cloak class="text-red-600" x-text="err"></span>
    </div>
  </div>
  @if(! $loc)
    <div class="card p-6 text-slate-500">{{ __('Your account is not linked to a branch, so there is no kitchen to show. Ask an admin to set your location.') }}</div>
  @endif
  <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3">
    <template x-for="o in orders" :key="o.id">
      <div class="card p-3 flex flex-col" :class="o.age > 15 ? 'border-red-400 border-2' : (fresh.includes(o.id) ? 'border-brand-500 border-2' : '')">
        <div class="flex justify-between font-bold"><span x-text="o.table ? '{{ __('Table') }} '+o.table : o.type.replace('_',' ')"></span><span x-text="o.age+' min'" :class="o.age>15 ? 'text-red-600' : 'text-slate-500'"></span></div>
        <div class="text-xs text-slate-400 mb-1" x-text="o.number+' · '+o.at"></div>
        <div x-show="o.qr" class="text-xs rounded bg-purple-50 text-purple-800 px-2 py-1 mb-2 font-semibold">📱 {{ __('QR order from the table') }}</div>
        <div x-show="o.delivery" class="text-xs rounded bg-orange-50 text-orange-800 px-2 py-1 mb-2"><b x-text="(o.online ? '🌐 ' : '')+'🛵 HOME DELIVERY'"></b> · <span x-text="o.delivery"></span><span x-show="o.rider" x-text="' · rider '+o.rider"></span></div>
        <template x-for="l in o.lines" :key="l.id"><button type="button" class="w-full text-left flex justify-between items-start py-2 px-2 rounded hover:bg-green-50 border-b" @click="ready(l.id)">
          <span><b x-text="l.qty+'×'"></b> <span x-text="l.name"></span> <span class="text-xs text-slate-400" x-text="l.name_bn"></span>
            <span x-show="l.note" class="block text-xs text-amber-700" x-text="'📝 '+l.note"></span></span><span class="text-green-600">✓</span></button></template>
        <button type="button" class="btn btn-sm mt-2 bg-green-600 text-white hover:bg-green-700" @click="orderReady(o.id)">✓ {{ __('Whole order ready') }}</button>
      </div>
    </template>
  </div>
  <div x-show="loaded && !orders.length && {{ $loc ? 'true' : 'false' }}" x-cloak class="text-slate-400 mt-2">{{ __('No pending kitchen orders') }} 👨‍🍳</div>
</div>
@push('scripts')
<script>
function kds(){ return { orders:[], loaded:false, err:'', fresh:[], seen:null, sound:true,
  items(){ return this.orders.reduce((n,o)=>n+o.lines.length,0) },
  start(){ @if($loc) this.load(); setInterval(()=>this.load(), 8000); @endif },
  async load(){
    try {
      const r = await fetch('{{ route('pos.kds.feed', ['location' => $loc?->id]) }}', {headers:{Accept:'application/json'}});
      if (r.status === 401 || r.status === 419) { this.err = '{{ __('Signed out — reload the page') }}'; return }
      if (!r.ok) { this.err = 'Error '+r.status; return }
      const list = await r.json(); this.err = '';
      const ids = list.map(o=>o.id);
      if (this.seen) { this.fresh = ids.filter(i=>!this.seen.includes(i)); if (this.fresh.length && this.sound) this.beep() }
      this.seen = ids; this.orders = list; this.loaded = true;
    } catch (e) { this.err = '{{ __('Offline — retrying') }}' }
  },
  post(url){ return fetch(url,{method:'POST',headers:{'X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content,Accept:'application/json'}}) },
  async ready(id){ await this.post('{{ url('/kds') }}/'+id+'/ready'); this.load() },
  async orderReady(id){ await this.post('{{ url('/kds/order') }}/'+id+'/ready'); this.load() },
  beep(){ try { const c=new (window.AudioContext||window.webkitAudioContext)(), o=c.createOscillator(), g=c.createGain(); o.connect(g); g.connect(c.destination); o.frequency.value=880; g.gain.value=0.15; o.start(); setTimeout(()=>{o.stop(); c.close()}, 350) } catch(e){} } } }
</script>
@endpush
@endsection
