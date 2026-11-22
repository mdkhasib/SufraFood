@extends('layouts.app')
@section('title', __('POS billing'))
@section('content')
@if(!$shift)
<form method="post" action="{{ route('pos.open') }}" class="card p-6 max-w-md mx-auto text-center space-y-3">@csrf
  <div class="text-4xl">🧾</div><div class="font-semibold text-lg">Open your cashier shift</div>
  @if($branches->count())<select name="location_id" class="inp">@foreach($branches as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach</select>@endif
  <div><label class="lbl text-left">Opening cash in drawer ৳</label><input name="opening_cash" type="number" value="3000" class="inp"></div>
  <button class="btn btn-primary w-full justify-center">Open shift</button>
</form>
@else
<div x-data="pos()" x-init="init()" class="grid lg:grid-cols-5 gap-4">
  <div class="lg:col-span-3">
    <div class="flex flex-wrap gap-2 mb-3">
      <button class="btn btn-sm" :class="cat==='' ? 'btn-dark' : 'btn-light'" @click="cat=''">All</button>
      <template x-for="c in cats"><button class="btn btn-sm" :class="cat===c ? 'btn-dark' : 'btn-light'" @click="cat=c" x-text="c"></button></template>
      <input x-model="q" class="inp !w-40 ml-auto" placeholder="search…">
    </div>
    <div class="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-4 gap-2">
      <template x-for="m in menu.filter(m => (!cat || m.category===cat) && (!q || m.name.toLowerCase().includes(q.toLowerCase())))" :key="m.id">
        <button class="card p-3 text-left hover:border-brand-500 active:scale-95 transition" @click="add(m)">
          <div class="font-semibold text-sm" x-text="m.name"></div><div class="text-xs text-slate-400" x-text="m.name_bn"></div>
          <div class="text-brand-700 font-bold mt-1" x-text="'৳'+(+m.sale_price).toFixed(0)"></div></button>
      </template>
    </div>
    @if($open->count())
    <div class="card p-3 mt-4"><div class="font-semibold text-sm mb-2">Open orders (sent to kitchen, not paid)</div>
      @foreach($open as $o)<button class="btn btn-light btn-sm mr-1 mb-1" @click="load({{ $o->toJson() }})">{{ $o->number }} · {{ $o->table?->name ?? $o->order_type }} · ৳{{ number_format($o->total) }}</button>@endforeach
    </div>@endif
  </div>
  <div class="lg:col-span-2">
    <div class="card p-4 sticky top-16">
      <div class="flex items-center justify-between mb-2"><div class="font-semibold">Order <span x-text="orderNo || 'new'" class="text-slate-400"></span></div>
        <div class="text-xs text-slate-500">{{ $shift->location->name }} · shift since {{ $shift->opened_at->format('h:i A') }}</div></div>
      <div class="flex gap-1 mb-2 text-xs">
        <template x-for="t in ['DINE_IN','TAKEAWAY','DELIVERY']"><button class="btn btn-sm" :class="type===t ? 'btn-dark' : 'btn-light'" @click="type=t" :disabled="!!orderId" x-text="t.replace('_',' ')"></button></template>
        <select x-show="type==='DINE_IN'" x-model="table" class="inp !py-1 !w-24" :disabled="!!orderId"><option value="">table</option>@foreach($tables as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach</select>
      </div>
      <input x-model="phone" class="inp mb-2 !py-1" placeholder="customer phone (optional)" :disabled="!!orderId">
      <div class="divide-y max-h-64 overflow-y-auto">
        <template x-for="(l,i) in lines" :key="l.item_id"><div class="flex items-center gap-2 py-1.5 text-sm">
          <div class="flex-1" x-text="l.name"></div>
          <button class="btn btn-light btn-sm" @click="l.qty>1 ? l.qty-- : lines.splice(i,1)" :disabled="!!orderId">−</button><b x-text="l.qty"></b><button class="btn btn-light btn-sm" @click="l.qty++" :disabled="!!orderId">+</button>
          <div class="w-16 text-right" x-text="'৳'+(l.qty*l.price).toFixed(0)"></div></div></template>
        <div x-show="!lines.length" class="text-center text-slate-400 py-6 text-sm">Tap menu items to add</div>
      </div>
      <div class="text-sm mt-3 space-y-1">
        <div class="flex justify-between"><span>Subtotal</span><span x-text="'৳'+sub().toFixed(2)"></span></div>
        <div class="flex justify-between items-center"><span>Discount (max {{ $cfg['discount_limit'] }}%)</span><input type="number" x-model.number="discount" class="inp !w-24 !py-0.5 text-right" :disabled="!!orderId"></div>
        <div class="flex justify-between" x-show="cfg.sc>0"><span x-text="'Service '+cfg.sc+'%'"></span><span x-text="'৳'+sc().toFixed(2)"></span></div>
        <div class="flex justify-between"><span x-text="'VAT '+cfg.vat+'%'"></span><span x-text="'৳'+vat().toFixed(2)"></span></div>
        <div class="flex justify-between text-xl font-bold border-t pt-1"><span>Total</span><span x-text="'৳'+total().toFixed(2)"></span></div>
      </div>
      <div class="mt-3 space-y-2">
        <template x-for="(p,i) in pays"><div class="flex gap-1">
          <select x-model="p.payment_method_id" class="inp !py-1">@foreach($methods as $m)<option value="{{ $m->id }}" data-type="{{ $m->type }}">{{ $m->name }}</option>@endforeach</select>
          <input type="number" x-model.number="p.amount" class="inp !py-1 !w-28" placeholder="৳"><input x-model="p.reference" class="inp !py-1 !w-28" placeholder="TxID (MFS)"></div></template>
        <div class="flex gap-2 text-xs"><button class="btn btn-light btn-sm" @click="pays.push({payment_method_id:'{{ $methods->first()->id }}',amount:0,reference:''})">+ split payment</button>
          <span class="ml-auto" x-show="change()>0" x-text="'Change ৳'+change().toFixed(2)"></span></div>
      </div>
      <div class="grid grid-cols-2 gap-2 mt-3">
        <button class="btn btn-dark justify-center" @click="kot()" :disabled="!lines.length || !!orderId || busy">🍳 Send KOT</button>
        <button class="btn btn-green justify-center" @click="payNow()" :disabled="!lines.length || busy">💰 Pay</button>
      </div>
      <div class="grid grid-cols-2 gap-2 mt-2"><button class="btn btn-light btn-sm justify-center" @click="printBill()" :disabled="!orderId" title="Guest bill before payment">🧾 Print bill</button>
        <button class="btn btn-light btn-sm justify-center" @click="reset()">Clear</button></div>
      <div x-show="msg" x-text="msg" class="mt-2 text-sm rounded px-2 py-1" :class="err ? 'bg-red-50 text-red-700' : 'bg-green-50 text-green-700'"></div>
    </div>
    <form method="post" action="{{ route('pos.close') }}" class="card p-3 mt-3 flex gap-2 items-end" onsubmit="return confirm('Close shift?')">@csrf
      <div class="flex-1"><label class="lbl">Count cash in drawer to close shift ৳</label><input name="counted_cash" type="number" step="any" class="inp" required></div><button class="btn btn-light">Close shift</button></form>
  </div>
</div>
@push('scripts')
<script>
function pos(){ return {
  menu: @json($menu), cfg: @json($cfg), cats: [], cat:'', q:'', lines:[], type:'DINE_IN', table:'', phone:'', discount:0,
  pays:[{payment_method_id:'{{ $methods->first()->id }}', amount:0, reference:''}], orderId:null, orderNo:null, busy:false, msg:'', err:false,
  init(){ this.cats=[...new Set(this.menu.map(m=>m.category))] },
  add(m){ if(this.orderId) return; const l=this.lines.find(x=>x.item_id===m.id); l ? l.qty++ : this.lines.push({item_id:m.id,name:m.name,price:+m.sale_price,qty:1}); },
  sub(){ return this.lines.reduce((s,l)=>s+l.qty*l.price,0) }, net(){ return this.sub()-(+this.discount||0) },
  sc(){ return Math.round(this.net()*this.cfg.sc)/100 }, vat(){ return Math.round((this.net()+this.sc())*this.cfg.vat)/100 },
  total(){ return Math.round((this.net()+this.sc()+this.vat())*100)/100 },
  change(){ return this.pays.reduce((s,p)=>s+(+p.amount||0),0)-this.total() },
  load(o){ this.reset(); this.orderId=o.id; this.orderNo=o.number; this.type=o.order_type; this.discount=+o.discount;
    this.lines=o.lines.map(l=>({item_id:l.item_id,name:l.item.name,price:+l.price,qty:+l.qty})); this.pays[0].amount=+o.total; },
  reset(){ this.lines=[]; this.orderId=null; this.orderNo=null; this.discount=0; this.table=''; this.phone=''; this.pays=[{payment_method_id:'{{ $methods->first()->id }}',amount:0,reference:''}] },
  async req(url, body){ this.busy=true; this.msg=''; try{ const r=await fetch(url,{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content},body:JSON.stringify(body)});
      const j=await r.json(); if(!r.ok){ this.err=true; this.msg=j.message||'Error'; return null } this.err=false; this.msg=j.message; return j } finally{ this.busy=false } },
  async kot(){ const j=await this.req('{{ route('pos.store') }}',{order_type:this.type,dining_table_id:this.table||null,customer_phone:this.phone,discount:+this.discount||0,lines:this.lines.map(l=>({item_id:l.item_id,qty:l.qty}))});
    if(j){ this.orderId=j.order.id; this.orderNo=j.order.number; this.pays[0].amount=+j.order.total } },
  async payNow(){ if(!this.orderId){ await this.kot(); if(!this.orderId) return }
    if(this.pays.length===1 && !+this.pays[0].amount) this.pays[0].amount=this.total();
    const tend=this.pays.reduce((s,p)=>s+(+p.amount||0),0), chg=this.change();
    const j=await this.req('{{ url('/pos/orders') }}/'+this.orderId+'/pay',{payments:this.pays});
    if(j){ window.open(j.receipt+'?tendered='+tend,'_blank','width=400,height=650'); const m=this.msg; this.reset(); this.msg=m+(chg>0.009?' · Change ৳'+chg.toFixed(2):'') } },
  printBill(){ if(this.orderId) window.open('{{ url('/pos/receipt') }}/'+this.orderId,'_blank','width=400,height=650') }
} }
</script>
@endpush
@endif
@endsection
