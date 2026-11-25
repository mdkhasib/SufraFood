@extends('layouts.app')
@section('title', __('Table calls'))
@section('content')
<div x-data="tableCalls()" x-init="init()" @click.once="sound=true">
  <div class="flex flex-wrap items-center gap-2 mb-3 text-sm">
    <span class="text-slate-500">{{ __('Live: refreshes every 8 seconds') }}</span>
    <span class="text-xs" :class="sound ? 'text-green-700' : 'text-amber-700'" x-text="sound ? '🔔 {{ __('sound on') }}' : '🔕 {{ __('tap anywhere to turn the sound on') }}'"></span>
    @if(auth()->user()->isGroupWide())
      <select x-model="loc" @change="load()" class="inp !w-48 !py-1 ml-auto"><option value="">{{ __('All branches') }}</option>@foreach(\App\Models\Location::where('type','BRANCH')->orderBy('name')->get() as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach</select>
    @endif
  </div>

  <div class="font-semibold mb-2">🙋 {{ __('Tables calling') }} <span class="badge bg-red-600 text-white" x-show="calls.length" x-text="calls.length"></span></div>
  <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3 mb-6">
    <template x-for="c in calls" :key="c.id">
      <div class="card p-4 border-2" :class="c.status==='OPEN' ? (c.mins>=3 ? 'border-red-500' : 'border-amber-400') : 'border-green-400'">
        <div class="flex items-center gap-2"><div class="text-3xl font-black">T<span x-text="c.table.replace(/^T/,'')"></span></div>
          <div class="leading-tight"><div class="font-semibold" x-text="c.label"></div><div class="text-xs text-slate-500" x-text="c.branch+' · '+c.at+' ('+c.mins+' min)'"></div></div></div>
        <div x-show="c.note" class="mt-2 rounded bg-slate-50 px-2 py-1 text-sm" x-text="'“'+c.note+'”'"></div>
        <div class="mt-3 flex gap-2">
          <button x-show="c.status==='OPEN'" class="btn btn-primary btn-sm flex-1 justify-center" @click="answer(c,'coming')">🏃 {{ __('On my way') }}</button>
          <span x-show="c.status==='COMING'" class="text-sm text-green-700 flex-1 self-center" x-text="'✓ '+(c.by||'')+' {{ __('is going') }}'"></span>
          <button class="btn btn-light btn-sm" @click="answer(c,'done')">✓ {{ __('Done') }}</button>
        </div>
      </div>
    </template>
    <div x-show="!calls.length" class="text-slate-400 text-sm">{{ __('No table is calling right now') }} 👍</div>
  </div>

  <div class="font-semibold mb-2">📱 {{ __('QR orders not paid yet') }}</div>
  <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
    <template x-for="o in orders" :key="o.id">
      <div class="card p-4">
        <div class="flex items-center gap-2"><b class="text-lg" x-text="'Table '+o.table"></b><span class="text-xs text-slate-500" x-text="o.branch+' · '+o.at"></span><b class="ml-auto" x-text="'৳'+Math.round(o.total)"></b></div>
        <div class="text-xs text-slate-400" x-text="o.number"></div>
        <template x-for="(i,n) in o.items" :key="n"><div class="flex text-sm py-0.5"><span class="flex-1"><b x-text="i.qty+'×'"></b> <span x-text="i.name"></span><span class="text-xs text-amber-700" x-show="i.note" x-text="' · '+i.note"></span></span>
          <span class="text-xs" x-text="i.kot==='NEW' ? '🍳' : (i.kot==='READY' ? '✅ ready' : '🍽')"></span></div></template>
      </div>
    </template>
    <div x-show="!orders.length" class="text-slate-400 text-sm">{{ __('No open QR orders') }}</div>
  </div>
</div>
@push('scripts')
<script>
function tableCalls(){ return { calls:[], orders:[], loc:'', sound:false, seen:null,
  init(){ this.load(); setInterval(()=>this.load(), 8000) },
  beep(){ if(!this.sound) return; try{ const a=new (window.AudioContext||window.webkitAudioContext)(); [0,0.25].forEach(t=>{ const o=a.createOscillator(), g=a.createGain(); o.frequency.value=880; o.connect(g); g.connect(a.destination); g.gain.setValueAtTime(.25,a.currentTime+t); o.start(a.currentTime+t); o.stop(a.currentTime+t+.15) }) }catch(e){} },
  async load(){ try{ const r=await fetch('{{ route('calls.feed') }}'+(this.loc?'?location='+this.loc:''),{headers:{Accept:'application/json'}}); if(!r.ok) return; const j=await r.json();
    const ids=new Set([...j.calls.map(c=>'c'+c.id),...j.orders.map(o=>'o'+o.id)]);
    if(this.seen && [...ids].some(i=>!this.seen.has(i))) this.beep(); this.seen=ids;
    this.calls=j.calls; this.orders=j.orders }catch(e){} },
  async answer(c, what){ await fetch('{{ url('/table-calls') }}/'+c.id+'/'+what,{method:'POST',headers:{Accept:'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content}}); this.load() }
} }
</script>
@endpush
@endsection
