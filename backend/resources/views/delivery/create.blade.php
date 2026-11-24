@extends('layouts.app')
@section('title', __('New delivery order'))
@section('content')
<div x-data="dorder()" x-init="init()" class="grid lg:grid-cols-5 gap-4">
  <div class="lg:col-span-3 space-y-3">
    <div class="card p-4 grid sm:grid-cols-2 gap-3">
      <div class="sm:col-span-2"><label class="lbl">{{ __('Branch that cooks & delivers') }}</label>
        <select x-model="location_id" class="inp">@foreach($branches as $b)<option value="{{ $b->id }}">{{ $b->name }}{{ $b->address ? ' · '.$b->address : '' }}</option>@endforeach</select></div>
      <div><label class="lbl">{{ __('Customer phone') }} *</label><input x-model="c.customer_phone" @change="lookup()" class="inp" placeholder="01XXXXXXXXX" inputmode="tel">
        <div class="text-[11px] text-green-700" x-show="known" x-cloak>✓ {{ __('Returning customer, details filled in') }}</div></div>
      <div><label class="lbl">{{ __('Customer name') }}</label><input x-model="c.customer_name" class="inp"></div>
      <div class="sm:col-span-2"><label class="lbl">{{ __('Delivery address') }} *</label><textarea x-model="c.delivery_address" rows="2" class="inp" placeholder="{{ __('House, road, block, floor / flat') }}"></textarea></div>
      <div><label class="lbl">{{ __('Area') }}</label><input x-model="c.delivery_area" class="inp" placeholder="Gulshan-1, Banani…"></div>
      <div><label class="lbl">{{ __('Note for kitchen / rider') }}</label><input x-model="c.delivery_note" class="inp" placeholder="{{ __('less spicy, call before coming…') }}"></div>
    </div>
    <div class="card p-4">
      <div class="flex flex-wrap gap-2 mb-3">
        <button class="btn btn-sm" :class="cat==='' ? 'btn-dark' : 'btn-light'" @click="cat=''">{{ __('All') }}</button>
        <template x-for="ct in cats"><button class="btn btn-sm" :class="cat===ct ? 'btn-dark' : 'btn-light'" @click="cat=ct" x-text="ct"></button></template>
        <input x-model="q" class="inp !w-40 ml-auto" placeholder="{{ __('search…') }}">
      </div>
      <div class="grid grid-cols-2 sm:grid-cols-3 gap-2 max-h-[420px] overflow-y-auto">
        <template x-for="m in menu.filter(m => (!cat || m.category===cat) && (!q || (m.name+' '+(m.name_bn||'')).toLowerCase().includes(q.toLowerCase())))" :key="m.id">
          <button class="rounded-lg border overflow-hidden text-left hover:border-brand-500 active:scale-95 transition" @click="add(m)">
            <template x-if="m.img"><img :src="m.img" class="w-full h-16 object-cover bg-slate-100" alt="" loading="lazy" onerror="this.remove()"></template>
            <div class="p-2.5 pt-1.5"><div class="font-semibold text-sm" x-text="m.name"></div><div class="text-xs text-slate-400" x-text="m.name_bn"></div>
            <div class="text-brand-700 font-bold" x-text="'৳'+(+m.sale_price).toFixed(0)"></div></div></button>
        </template>
      </div>
    </div>
  </div>
  <div class="lg:col-span-2">
    <div class="card p-4 sticky top-16">
      <div class="font-semibold mb-2">🛵 {{ __('Delivery order') }}</div>
      <div class="divide-y max-h-64 overflow-y-auto">
        <template x-for="(l,i) in lines" :key="l.item_id"><div class="flex items-center gap-2 py-1.5 text-sm">
          <div class="flex-1" x-text="l.name"></div>
          <button class="btn btn-light btn-sm" @click="l.qty>1 ? l.qty-- : lines.splice(i,1)">−</button><b x-text="l.qty"></b><button class="btn btn-light btn-sm" @click="l.qty++">+</button>
          <div class="w-16 text-right" x-text="'৳'+(l.qty*l.price).toFixed(0)"></div></div></template>
        <div x-show="!lines.length" class="text-center text-slate-400 py-6 text-sm">{{ __('Tap menu items to add') }}</div>
      </div>
      <div class="text-sm mt-3 space-y-1">
        <div class="flex justify-between"><span>{{ __('Subtotal') }}</span><span x-text="'৳'+sub().toFixed(2)"></span></div>
        <div class="flex justify-between items-center"><span>{{ __('Discount') }} (max {{ $cfg['discount_limit'] }}%)</span><input type="number" x-model.number="discount" class="inp !w-24 !py-0.5 text-right"></div>
        <div class="flex justify-between" x-show="cfg.sc>0"><span x-text="'Service '+cfg.sc+'%'"></span><span x-text="'৳'+sc().toFixed(2)"></span></div>
        <div class="flex justify-between"><span x-text="'VAT '+cfg.vat+'%'"></span><span x-text="'৳'+vat().toFixed(2)"></span></div>
        <div class="flex justify-between text-xl font-bold border-t pt-1"><span>{{ __('Total') }}</span><span x-text="'৳'+total().toFixed(2)"></span></div>
      </div>
      <div class="mt-3 grid grid-cols-2 gap-2 text-sm">
        <label class="rounded-lg border p-2 flex items-center gap-2 cursor-pointer" :class="mode==='COD' ? 'border-brand-600 bg-orange-50' : ''"><input type="radio" value="COD" x-model="mode"> 💵 {{ __('Cash on delivery') }}</label>
        <label class="rounded-lg border p-2 flex items-center gap-2 cursor-pointer" :class="mode==='PREPAID' ? 'border-brand-600 bg-orange-50' : ''" @if($methods->isEmpty()) title="{{ __('No online payment methods') }}" @endif><input type="radio" value="PREPAID" x-model="mode" @disabled($methods->isEmpty())> 📱 {{ __('Already paid') }}</label>
      </div>
      <div class="flex gap-1 mt-2" x-show="mode==='PREPAID'" x-cloak>
        <select x-model="pm" class="inp !py-1">@foreach($methods as $m)<option value="{{ $m->id }}">{{ $m->name }}</option>@endforeach</select>
        <input x-model="ref" class="inp !py-1" placeholder="{{ __('TxID / reference') }}">
      </div>
      <button class="btn btn-primary w-full justify-center mt-3 !py-2.5" @click="send()" :disabled="busy || !lines.length">🍳 {{ __('Send to branch kitchen') }}</button>
      <div x-show="msg" x-text="msg" class="mt-2 text-sm rounded px-2 py-1" :class="err ? 'bg-red-50 text-red-700' : 'bg-green-50 text-green-700'"></div>
      <div class="text-[11px] text-slate-500 mt-2">{{ __('The branch kitchen sees it on the kitchen screen and the branch manager gives it to a rider. You get a message when it is on the way and delivered.') }}</div>
    </div>
  </div>
</div>
@push('scripts')
<script>
function dorder(){ return {
  menu: @json($menu), cfg: @json($cfg), recent: @json($recent), cats: [], cat:'', q:'', lines:[], discount:0,
  location_id: '{{ $branches->first()->id }}', c:{customer_phone:'',customer_name:'',delivery_address:'',delivery_area:'',delivery_note:''},
  mode:'COD', pm:'{{ $methods->first()?->id }}', ref:'', busy:false, msg:'', err:false, known:false,
  init(){ this.cats=[...new Set(this.menu.map(m=>m.category))] },
  lookup(){ const p=this.c.customer_phone.replace(/\D/g,''); const r=this.recent.find(x=>(x.customer_phone||'').replace(/\D/g,'')===p);
    this.known=!!r; if(r){ this.c.customer_name ||= r.customer_name||''; this.c.delivery_address ||= r.delivery_address||''; this.c.delivery_area ||= r.delivery_area||'' } },
  add(m){ const l=this.lines.find(x=>x.item_id===m.id); l ? l.qty++ : this.lines.push({item_id:m.id,name:m.name,price:+m.sale_price,qty:1}) },
  sub(){ return this.lines.reduce((s,l)=>s+l.qty*l.price,0) }, net(){ return this.sub()-(+this.discount||0) },
  sc(){ return Math.round(this.net()*this.cfg.sc)/100 }, vat(){ return Math.round((this.net()+this.sc())*this.cfg.vat)/100 },
  total(){ return Math.round((this.net()+this.sc()+this.vat())*100)/100 },
  async send(){ this.busy=true; this.msg=''; try{
      const r=await fetch('{{ route('delivery.store') }}',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content},
        body:JSON.stringify({...this.c, location_id:this.location_id, discount:+this.discount||0, payment_mode:this.mode, payment_method_id:this.pm, reference:this.ref, lines:this.lines.map(l=>({item_id:l.item_id,qty:l.qty}))})});
      const j=await r.json(); this.err=!r.ok; this.msg=j.message||'Error';
      if(r.ok){ setTimeout(()=>location.href=j.redirect, 900) }
    } finally{ this.busy=false } }
} }
</script>
@endpush
@endsection
