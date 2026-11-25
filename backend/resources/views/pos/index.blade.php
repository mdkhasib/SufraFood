@extends('layouts.app')
@section('title', __('POS billing'))
@section('content')
@if(!$shift)
<form method="post" action="{{ route('pos.open') }}" class="card p-6 max-w-md mx-auto text-center space-y-3">@csrf
  <div class="text-4xl">🧾</div><div class="font-semibold text-lg">Open your cashier shift</div>
  @if($branches->count())<select name="location_id" class="inp">@foreach($branches as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach</select>@endif
  <div><label class="lbl text-left">Opening cash in drawer ৳</label><input name="opening_cash" type="number" value="3000" class="inp"></div>
  <button class="btn btn-primary w-full justify-center">Open shift</button>
  @if($cfg['offline'])<div class="text-xs text-slate-500">{{ __('Open the shift while the network works. After that the POS keeps selling even if the network or the server goes down.') }}</div>@endif
</form>
@else
<div x-data="pos()" x-init="init()" class="grid lg:grid-cols-5 gap-4">
  <div class="lg:col-span-3">
    @if($cfg['offline'])
    <div class="rounded-xl border p-2.5 mb-3 flex flex-wrap items-center gap-2 text-sm" :class="online ? 'bg-green-50 border-green-200' : 'bg-red-50 border-red-300'">
      <span class="inline-block w-2.5 h-2.5 rounded-full" :class="online ? 'bg-green-500' : 'bg-red-500 animate-pulse'"></span>
      <b x-text="online ? '{{ __('Online') }}' : '{{ __('OFFLINE: bills are saved on this computer') }}'"></b>
      <span class="text-xs text-slate-500" x-show="lastSync" x-text="'· {{ __('last sync') }} '+lastSyncLabel()"></span>
      <span class="text-xs text-slate-500" x-show="!swReady" x-cloak title="{{ __('Over plain http the browser cannot keep the page for reopening. Keep this tab open; bills are still saved.') }}">· {{ __('keep this tab open') }}</span>
      <span class="text-xs text-green-700" x-show="swReady" x-cloak>· {{ __('works offline') }} ✓</span>
      <button class="btn btn-sm ml-auto" :class="mine().length ? 'btn-primary' : 'btn-light'" @click="sync(false)" :disabled="syncing">
        <span x-text="syncing ? '{{ __('Syncing…') }}' : '🔄 {{ __('Sync') }}'"></span> <span x-show="mine().length" class="badge bg-white text-brand-700" x-text="mine().length"></span></button>
    </div>
    <div x-show="syncMsg" x-cloak x-text="syncMsg" class="text-xs rounded px-2 py-1 mb-2" :class="syncErr ? 'bg-red-50 text-red-700' : 'bg-slate-50 text-slate-600'"></div>
    <template x-if="mine().some(o=>o.error)"><div class="rounded-lg bg-red-50 border border-red-200 p-2 mb-3 text-xs text-red-800">
      <b>{{ __('These offline bills could not be synced:') }}</b>
      <template x-for="o in mine().filter(o=>o.error)" :key="o.uuid"><div><span x-text="o.ref"></span> · ৳<span x-text="o.total.toFixed(2)"></span> · <span x-text="o.error"></span></div></template>
      <div class="mt-1">{{ __('They stay safe on this computer. Ask the manager / accounts, then press Sync again.') }}</div></div></template>
    @endif
    @if($cfg['qr'])
    <div class="rounded-xl border border-purple-200 bg-purple-50 p-2.5 mb-3 text-sm" x-show="qrCalls.length || qrOrders.length" x-cloak @click.once="sound=true">
      <div class="font-semibold text-purple-900 mb-1">📱 {{ __('Tables') }} <span class="text-xs font-normal text-purple-700" x-show="!sound">· {{ __('tap here to turn the sound on') }}</span></div>
      <template x-for="c in qrCalls" :key="'c'+c.id"><div class="flex items-center gap-2 py-1 border-b border-purple-100">
        <b class="w-12" x-text="c.table"></b><span class="flex-1" x-text="c.label+(c.note ? ' · '+c.note : '')+' · '+c.mins+' min'"></span>
        <button x-show="c.status==='OPEN'" class="btn btn-primary btn-sm" @click="qrAnswer(c,'coming')">🏃 {{ __('Coming') }}</button>
        <span x-show="c.status==='COMING'" class="text-xs text-green-700" x-text="'✓ '+(c.by||'')"></span>
        <button class="btn btn-light btn-sm" @click="qrAnswer(c,'done')">✓</button></div></template>
      <template x-for="o in qrOrders" :key="'o'+o.id"><div class="flex items-center gap-2 py-1">
        <b class="w-12" x-text="o.table"></b><span class="flex-1 truncate" x-text="o.items.map(i=>i.qty+'× '+i.name).join(', ')"></span><b x-text="'৳'+Math.round(o.total)"></b>
        <button class="btn btn-light btn-sm" @click="load(o.order)">{{ __('Open bill') }}</button></div></template>
    </div>
    @endif
    <div class="flex flex-wrap gap-2 mb-3">
      <button class="btn btn-sm" :class="cat==='' ? 'btn-dark' : 'btn-light'" @click="cat=''">All</button>
      <template x-for="c in cats"><button class="btn btn-sm" :class="cat===c ? 'btn-dark' : 'btn-light'" @click="cat=c" x-text="c"></button></template>
      <input x-model="q" class="inp !w-40 ml-auto" placeholder="search…">
    </div>
    <div class="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-4 gap-2">
      <template x-for="m in menu.filter(m => (!cat || m.category===cat) && (!q || m.name.toLowerCase().includes(q.toLowerCase())))" :key="m.id">
        <button class="card p-0 overflow-hidden text-left hover:border-brand-500 active:scale-95 transition" @click="add(m)">
          <template x-if="m.img"><img :src="m.img" class="w-full h-20 object-cover bg-slate-100" alt="" loading="lazy" onerror="this.remove()"></template>
          <div class="p-3 pt-2"><div class="font-semibold text-sm" x-text="m.name"></div><div class="text-xs text-slate-400" x-text="m.name_bn"></div>
          <div class="text-brand-700 font-bold mt-1" x-text="'৳'+(+m.sale_price).toFixed(0)"></div></div></button>
      </template>
    </div>
    <div class="card p-3 mt-4" x-show="serverOpen.length || myOpen().length"><div class="font-semibold text-sm mb-2">Open orders (sent to kitchen, not paid)</div>
      <template x-for="o in serverOpen" :key="'s'+o.id"><button class="btn btn-light btn-sm mr-1 mb-1" @click="load(o)" x-text="o.number+' · '+(o.table ? o.table.name : o.order_type)+' · ৳'+Math.round(o.total)"></button></template>
      <template x-for="o in myOpen()" :key="o.lid"><button class="btn btn-sm mr-1 mb-1 bg-amber-100 text-amber-900" @click="loadLocal(o)" x-text="'📴 '+o.ref+' · '+(o.table_name || o.order_type)+' · ৳'+Math.round(o.total)"></button></template>
    </div>
  </div>
  <div class="lg:col-span-2">
    <div class="card p-4 sticky top-16">
      <div class="flex items-center justify-between mb-2"><div class="font-semibold">Order <span x-text="orderNo || 'new'" class="text-slate-400"></span> <span x-show="isLocal()" x-cloak class="badge bg-amber-100 text-amber-800">offline</span></div>
        <div class="text-xs text-slate-500">{{ $shift->location->name }} · shift since {{ $shift->opened_at->format('h:i A') }}</div></div>
      <div class="flex gap-1 mb-2 text-xs">
        <template x-for="t in ['DINE_IN','TAKEAWAY','DELIVERY']"><button class="btn btn-sm" :class="type===t ? 'btn-dark' : 'btn-light'" @click="type=t" :disabled="!!orderId" x-text="t.replace('_',' ')"></button></template>
        <select x-show="type==='DINE_IN'" x-model="table" class="inp !py-1 !w-24" :disabled="!!orderId"><option value="">table</option>@foreach($tables as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach</select>
      </div>
      <input x-model="phone" class="inp mb-2 !py-1" :placeholder="type==='DELIVERY' && cfg.delivery ? 'customer phone *' : 'customer phone (optional)'" :disabled="!!orderId">
      @if($cfg['delivery'])
      <div x-show="type==='DELIVERY'" x-cloak class="rounded-lg bg-orange-50 border border-orange-200 p-2 mb-2 space-y-1">
        <div class="text-xs font-semibold text-orange-800">🛵 {{ __('Home delivery: the manager gives it to a rider') }}</div>
        <input x-model="cust.customer_name" class="inp !py-1" placeholder="{{ __('customer name') }}" :disabled="!!orderId">
        <textarea x-model="cust.delivery_address" rows="2" class="inp !py-1" placeholder="{{ __('delivery address *') }}" :disabled="!!orderId"></textarea>
        <div class="flex gap-1"><input x-model="cust.delivery_area" class="inp !py-1" placeholder="{{ __('area') }}" :disabled="!!orderId"><input x-model="cust.delivery_note" class="inp !py-1" placeholder="{{ __('note') }}" :disabled="!!orderId"></div>
        <div class="text-[11px] text-orange-800">{{ __('Send KOT now; take the cash when the rider brings it back (Home delivery board), or press Pay if the customer already paid.') }}</div>
      </div>
      @endif
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
      @if($cfg['delivery'])<a href="{{ route('delivery.index') }}" class="btn btn-light btn-sm w-full justify-center mt-2" x-show="online">🛵 {{ __('Home delivery board') }}@if($deliveriesOpen) <span class="badge bg-red-600 text-white">{{ $deliveriesOpen }}</span>@endif</a>@endif
      <div class="grid grid-cols-2 gap-2 mt-2"><button class="btn btn-light btn-sm justify-center" @click="printBill()" :disabled="!orderId" title="Guest bill before payment">🧾 Print bill</button>
        <button class="btn btn-light btn-sm justify-center" @click="isLocal() ? dropLocal() : reset()" x-text="isLocal() ? '🗑 Cancel offline order' : 'Clear'"></button></div>
      <div x-show="msg" x-text="msg" class="mt-2 text-sm rounded px-2 py-1" :class="err ? 'bg-red-50 text-red-700' : 'bg-green-50 text-green-700'"></div>
    </div>
    <form method="post" action="{{ route('pos.close') }}" class="card p-3 mt-3 flex gap-2 items-end" @submit="if(!canClose()){ $event.preventDefault(); return } if(!confirm('Close shift?')) $event.preventDefault()">@csrf
      <div class="flex-1"><label class="lbl">Count cash in drawer to close shift ৳</label><input name="counted_cash" type="number" step="any" class="inp" required></div><button class="btn btn-light">Close shift</button></form>
  </div>
</div>
@push('scripts')
<script src="{{ asset('js/pos-offline.js') }}?v=1"></script>
<script>
function pos(){ const P = window.PosOffline; const firstPm = '{{ $methods->first()->id }}'; return {
  menu: @json($menu), cfg: @json($cfg), methods: @json($methods), tables: @json($tables), serverOpen: @json($open),
  cats: [], cat:'', q:'', lines:[], type:'DINE_IN', table:'', phone:'', discount:0, cust:{customer_name:'',delivery_address:'',delivery_area:'',delivery_note:''},
  pays:[{payment_method_id:firstPm, amount:0, reference:''}], orderId:null, orderNo:null, busy:false, msg:'', err:false,
  online: true, outbox: [], localOpen: [], lastSync: null, syncing:false, syncMsg:'', syncErr:false, swReady:false,
  init(){
    this.cats=[...new Set(this.menu.map(m=>m.category))];
    if(this.cfg.qr){ this.qrLoad(); setInterval(()=>this.qrLoad(), 15000) }   // QR orders + table calls
    if(!this.cfg.offline) return;
    this.outbox=P.load('outbox'); this.localOpen=P.load('open'); this.lastSync=P.get('lastSync');
    P.registerSW().then(ok=>this.swReady=ok);
    this.online = navigator.onLine;
    this.ping().then(()=>{ if(this.online && this.mine().length) this.sync(true) });
    setInterval(()=>this.ping(), 30000);
    setInterval(()=>this.sync(true), this.cfg.sync_minutes*60000);   // auto sync (Company settings → POS, default 10 min)
    window.addEventListener('online', ()=>this.ping());
    window.addEventListener('offline', ()=>{ this.online=false });
  },
  // ---------- QR table orders & calls ----------
  qrCalls:[], qrOrders:[], qrSeen:null, sound:false,
  async qrLoad(){ try{ const r=await fetch('{{ route('calls.feed') }}?location='+this.cfg.location_id,{headers:{Accept:'application/json'}}); if(!r.ok) return; const j=await r.json();
    const ids=new Set([...j.calls.map(c=>'c'+c.id),...j.orders.map(o=>'o'+o.id+'-'+o.items.length)]);
    if(this.qrSeen && [...ids].some(i=>!this.qrSeen.has(i))) this.beep(); this.qrSeen=ids;
    this.qrCalls=j.calls; this.qrOrders=j.orders;
    for(const o of j.orders){ const i=this.serverOpen.findIndex(s=>s.id===o.id); i<0 ? this.serverOpen.push(o.order) : (this.serverOpen[i]=o.order) } }catch(e){} },
  async qrAnswer(c, what){ await fetch('{{ url('/table-calls') }}/'+c.id+'/'+what,{method:'POST',headers:{Accept:'application/json','X-CSRF-TOKEN':this.csrf()}}); this.qrLoad() },
  beep(){ if(!this.sound) return; try{ const a=new (window.AudioContext||window.webkitAudioContext)(); [0,0.25].forEach(t=>{ const o=a.createOscillator(), g=a.createGain(); o.frequency.value=880; o.connect(g); g.connect(a.destination); g.gain.setValueAtTime(.25,a.currentTime+t); o.start(a.currentTime+t); o.stop(a.currentTime+t+.15) }) }catch(e){} },
  // ---------- helpers ----------
  mine(){ return this.outbox.filter(o=>o.user_id===this.cfg.user.id) },
  myOpen(){ return this.localOpen.filter(o=>o.user_id===this.cfg.user.id) },
  isLocal(){ return typeof this.orderId==='string' && this.orderId.startsWith('L') },
  lastSyncLabel(){ return this.lastSync ? new Date(this.lastSync).toLocaleTimeString('en-US',{hour:'2-digit',minute:'2-digit'}) : '' },
  csrf(){ return document.querySelector('meta[name=csrf-token]').content },
  async ping(){ const was=this.online; const p=await P.ping('{{ route('pos.ping') }}');
    this.online = !!(p && p.ok);
    if(p && p.csrf) document.querySelector('meta[name=csrf-token]').content=p.csrf;
    if(p && p.login){ this.syncErr=true; this.syncMsg='Your login has expired. Log in again (bills on this computer are safe), then press Sync.' }
    if(!was && this.online && this.mine().length) this.sync(true);   // network is back
    return p },
  add(m){ if(this.orderId) return; const l=this.lines.find(x=>x.item_id===m.id); l ? l.qty++ : this.lines.push({item_id:m.id,name:m.name,name_bn:m.name_bn,price:+m.sale_price,qty:1}); },
  sub(){ return this.lines.reduce((s,l)=>s+l.qty*l.price,0) }, net(){ return this.sub()-(+this.discount||0) },
  sc(){ return Math.round(this.net()*this.cfg.sc)/100 }, vat(){ return Math.round((this.net()+this.sc())*this.cfg.vat)/100 },
  total(){ return Math.round((this.net()+this.sc()+this.vat())*100)/100 },
  change(){ return this.pays.reduce((s,p)=>s+(+p.amount||0),0)-this.total() },
  load(o){ this.reset(); this.orderId=o.id; this.orderNo=o.number; this.type=o.order_type; this.discount=+o.discount;
    this.lines=o.lines.map(l=>({item_id:l.item_id,name:l.item.name,name_bn:l.item.name_bn,price:+l.price,qty:+l.qty})); this.pays[0].amount=+o.total; this._server=o },
  loadLocal(o){ this.reset(); this.orderId=o.lid; this.orderNo=o.ref; this.type=o.order_type; this.table=o.dining_table_id||''; this.phone=o.customer_phone||''; this.discount=o.discount;
    this.lines=o.lines.map(l=>({...l})); this.pays[0].amount=o.total },
  reset(){ this.lines=[]; this.orderId=null; this.orderNo=null; this._server=null; this.discount=0; this.table=''; this.phone=''; this.cust={customer_name:'',delivery_address:'',delivery_area:'',delivery_note:''}; this.pays=[{payment_method_id:firstPm,amount:0,reference:''}] },
  /** POST to the server; {offline:true} when the network / server cannot be reached. */
  async req(url, body){ this.busy=true; this.msg=''; try{
      let r; try{ r=await fetch(url,{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':this.csrf()},body:JSON.stringify(body)}) }
      catch(e){ this.online=false; return {offline:true} }
      if([502,503,504].includes(r.status)){ this.online=false; return {offline:true} }
      const j=await r.json().catch(()=>({message:'Error '+r.status}));
      if(!r.ok){ this.err=true; this.msg=j.message||'Error'; return null } this.online=true; this.err=false; this.msg=j.message; return j } finally{ this.busy=false } },
  // ---------- selling ----------
  async kot(){
    if(this.cfg.offline && !this.online) return this.localKot();
    const j=await this.req('{{ route('pos.store') }}',{...(this.type==='DELIVERY' ? this.cust : {}), order_type:this.type,dining_table_id:this.table||null,customer_phone:this.phone,discount:+this.discount||0,lines:this.lines.map(l=>({item_id:l.item_id,qty:l.qty}))});
    if(j && j.offline) return this.cfg.offline ? this.localKot() : this.fail('Server not reachable.');
    if(j){ this.orderId=j.order.id; this.orderNo=j.order.number; if(!(+this.pays[0].amount)) this.pays[0].amount=+j.order.total; this.serverOpen.push(j.order) } },
  fail(m){ this.err=true; this.msg=m; return false },
  /** Build the offline bill record from the screen. */
  draft(){
    if(this.type==='DELIVERY') return this.fail('Home delivery needs the network (rider, board). Take it as TAKEAWAY or wait for the network.');
    const sub=this.sub(), d=+this.discount||0;
    if(d>sub) return this.fail('Discount is more than the bill.');
    if(sub>0 && d/sub*100 > this.cfg.discount_limit+0.001) return this.fail('Discount is above your limit of '+this.cfg.discount_limit+'%.');
    const t=this.tables.find(x=>String(x.id)===String(this.table));
    return { lid:'L'+P.uuid(), uuid:P.uuid(), ref:P.nextRef(this.cfg.user.code), user_id:this.cfg.user.id, location_id:this.cfg.location_id,
      order_type:this.type, dining_table_id:this.type==='DINE_IN' ? (this.table||null) : null, table_name:this.type==='DINE_IN' && t ? t.name : null,
      customer_phone:this.phone||null, discount:d, lines:this.lines.map(l=>({item_id:l.item_id,name:l.name,name_bn:l.name_bn,qty:l.qty,price:l.price})),
      subtotal:sub, sc:this.sc(), vat:this.vat(), total:this.total(), vat_pct:this.cfg.vat, sc_pct:this.cfg.sc, created_at:new Date().toISOString() } },
  localKot(){ const o=this.draft(); if(!o) return false;
    this.localOpen.push(o); P.save('open', this.localOpen);
    this.orderId=o.lid; this.orderNo=o.ref; if(!(+this.pays[0].amount)) this.pays[0].amount=o.total;
    P.print(P.kotHtml(o, this.cfg.receipt));
    this.err=false; this.msg='📴 Offline: kitchen ticket printed · '+o.ref; return true },
  async payNow(){
    if(!this.orderId){ if(this.cfg.offline && !this.online){ if(!this.localKot()) return } else { await this.kot(); if(!this.orderId) return } }
    if(this.pays.length===1 && !+this.pays[0].amount) this.pays[0].amount=this.total();
    if(this.isLocal()) return this.localPay();
    const tend=this.pays.reduce((s,p)=>s+(+p.amount||0),0), chg=this.change();
    const j=await this.req('{{ url('/pos/orders') }}/'+this.orderId+'/pay',{payments:this.pays});
    if(j && j.offline) return this.cfg.offline ? this.localPay(this._server) : this.fail('Server not reachable.');
    if(j){ this.serverOpen=this.serverOpen.filter(o=>o.id!==this.orderId); window.open(j.receipt+'?tendered='+tend,'_blank','width=400,height=650'); const m=this.msg; this.reset(); this.msg=m+(chg>0.009?' · Change ৳'+chg.toFixed(2):'') } },
  /** Take the money with no network: the bill is printed now and synced later. */
  localPay(server){
    let o = this.isLocal() ? this.localOpen.find(x=>x.lid===this.orderId) : null;
    if(!o){ o=this.draft(); if(!o) return }
    if(server){ o.server_order_id=server.id; o.ref=server.number; o.subtotal=+server.subtotal; o.discount=+server.discount; o.sc=+server.service_charge; o.vat=+server.vat; o.total=+server.total; o.created_at=server.created_at }
    const pays=this.pays.filter(p=>+p.amount>0).map(p=>{ const m=this.methods.find(x=>String(x.id)===String(p.payment_method_id)); return {payment_method_id:+p.payment_method_id,name:m?.name,type:m?.type,amount:+p.amount,reference:p.reference||null} });
    const sum=pays.reduce((s,p)=>s+p.amount,0);
    if(sum+0.009 < o.total) return this.fail('Paid ৳'+sum.toFixed(2)+' is less than total ৳'+o.total.toFixed(2)+'.');
    const bad=pays.find(p=>p.type==='MFS' && !p.reference); if(bad) return this.fail('Enter the '+bad.name+' transaction ID.');
    let chg=Math.round((sum-o.total)*100)/100;
    for(const p of pays){ if(chg>0 && p.type==='CASH'){ const take=Math.min(chg,p.amount); p.amount=Math.round((p.amount-take)*100)/100; chg=Math.round((chg-take)*100)/100 } }
    if(chg>0.009) return this.fail('Only cash can be overpaid.');
    const rec={...o, payments:pays.filter(p=>p.amount>0), tendered:sum, paid_at:new Date().toISOString()};
    delete rec.lid;
    this.outbox.push(rec);
    if(!P.save('outbox', this.outbox)){ this.outbox.pop(); return this.fail('This computer’s storage is full: sync before selling more.') }
    this.localOpen=this.localOpen.filter(x=>x.lid!==this.orderId); P.save('open', this.localOpen);
    if(server) this.serverOpen=this.serverOpen.filter(x=>x.id!==server.id);
    P.print(P.receiptHtml(rec, this.cfg.receipt, this.cfg.user.name));
    const change=sum-rec.total; this.reset(); this.err=false;
    this.msg='📴 Paid offline · '+rec.ref+(change>0.009 ? ' · Change ৳'+change.toFixed(2) : '')+' · will sync';
  },
  dropLocal(){ if(!confirm('Cancel this offline order? (it was never sent to the server)')) return;
    this.localOpen=this.localOpen.filter(x=>x.lid!==this.orderId); P.save('open', this.localOpen); this.reset(); this.msg='Offline order cancelled' },
  printBill(){ if(!this.orderId) return;
    if(this.isLocal()){ const o=this.localOpen.find(x=>x.lid===this.orderId); if(o) P.print(P.receiptHtml({...o,payments:[]}, this.cfg.receipt, this.cfg.user.name)); return }
    if(!this.online && this._server){ const s=this._server; return P.print(P.receiptHtml({ref:s.number,order_type:s.order_type,lines:this.lines,subtotal:+s.subtotal,discount:+s.discount,sc:+s.service_charge,vat:+s.vat,vat_pct:this.cfg.vat,total:+s.total,created_at:s.created_at,payments:[]}, this.cfg.receipt, this.cfg.user.name)) }
    window.open('{{ url('/pos/receipt') }}/'+this.orderId,'_blank','width=400,height=650') },
  // ---------- sync ----------
  async sync(auto){
    if(this.syncing) return;
    const list=this.mine();
    if(!list.length){ if(!auto){ this.syncErr=false; this.syncMsg='Nothing to sync: every bill is on the server.'; this.ping() } return }
    this.syncing=true; this.syncErr=false; this.syncMsg='';
    try{
      const p=await this.ping();
      if(!p || !p.ok){ this.syncErr=true; this.syncMsg = p && p.login ? this.syncMsg : 'Still offline: '+list.length+' bill(s) waiting on this computer. Will try again automatically.'; return }
      let r; try{ r=await fetch('{{ route('pos.sync') }}',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':this.csrf()},body:JSON.stringify({orders:list.map(({error,...o})=>o)})}) }
      catch(e){ this.online=false; this.syncErr=true; this.syncMsg='Network dropped during sync. Will try again.'; return }
      if(r.status===401 || r.status===419){ this.syncErr=true; this.syncMsg='Your login has expired. Log in again, then press Sync. Bills are safe on this computer.'; return }
      const j=await r.json().catch(()=>null);
      if(!r.ok || !j){ this.syncErr=true; this.syncMsg=(j && j.message) || ('Sync failed ('+r.status+')'); return }
      const done=new Set(j.results.filter(x=>x.ok).map(x=>x.uuid)), errs={}; j.results.filter(x=>!x.ok).forEach(x=>errs[x.uuid]=x.error);
      this.outbox=this.outbox.filter(o=>!done.has(o.uuid)).map(o=>errs[o.uuid] ? {...o,error:errs[o.uuid]} : o);
      P.save('outbox', this.outbox);
      this.lastSync=new Date().toISOString(); P.set('lastSync', this.lastSync);
      this.syncErr=Object.keys(errs).length>0; this.syncMsg='✓ '+j.message+(auto ? ' (auto)' : '');
    } finally{ this.syncing=false }
  },
  canClose(){
    if(!this.cfg.offline) return true;
    if(!this.online){ alert('The server is not reachable. Close the shift when the network is back.'); return false }
    if(this.mine().length){ alert('Sync the offline bills first (🔄 Sync), so the cash count matches.'); return false }
    if(this.myOpen().length){ alert('Pay or cancel the open offline orders first.'); return false }
    return true }
} }
</script>
@endpush
@endif
@endsection
