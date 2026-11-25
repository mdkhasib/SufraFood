@php($brand = \App\Support\Brand::name())
@php($logo = \App\Support\Brand::logoUrl())
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="robots" content="noindex">
<title>Table {{ $t->name }} · {{ $t->location->name }}</title>
<link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;600;700&family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com/3.4.16"></script>
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.8/dist/cdn.min.js"></script>
<style>body{font-family:Inter,'Hind Siliguri',system-ui,sans-serif;-webkit-tap-highlight-color:transparent}[x-cloak]{display:none!important}.no-scrollbar::-webkit-scrollbar{display:none}</style>
</head>
<body class="bg-orange-50/40 text-slate-800" x-data="qrMenu()" x-init="init()">
<div class="max-w-xl mx-auto pb-36">
  {{-- header --}}
  <div class="bg-white px-4 pt-4 pb-3 border-b sticky top-0 z-20">
    <div class="flex items-center gap-3">
      @if($logo)<img src="{{ $logo }}" class="h-11 w-11 rounded-xl object-contain border bg-white" alt="">@else<div class="h-11 w-11 rounded-xl bg-orange-600 text-white grid place-items-center font-bold">{{ \App\Support\Brand::initials() }}</div>@endif
      <div class="min-w-0"><div class="font-bold leading-tight">{{ $brand }}</div><div class="text-xs text-slate-500">{{ $t->location->name }}</div></div>
      <div class="ml-auto rounded-xl bg-slate-900 text-white px-3 py-1.5 text-center leading-tight"><div class="text-[10px] uppercase tracking-wide opacity-70">Table</div><div class="font-bold text-lg">{{ $t->name }}</div></div>
    </div>
    <div class="flex gap-2 overflow-x-auto no-scrollbar mt-3 -mx-1 px-1">
      <button class="shrink-0 rounded-full px-3 py-1.5 text-sm font-semibold" :class="cat==='' ? 'bg-orange-600 text-white' : 'bg-slate-100'" @click="cat=''">All</button>
      <template x-for="c in cats" :key="c"><button class="shrink-0 rounded-full px-3 py-1.5 text-sm font-semibold" :class="cat===c ? 'bg-orange-600 text-white' : 'bg-slate-100'" @click="cat=c" x-text="c"></button></template>
    </div>
  </div>

  @if($cfg['welcome'])<div class="px-4 pt-3 text-sm text-slate-600">{{ $cfg['welcome'] }}</div>@endif

  {{-- call buttons --}}
  @if($cfg['calls'])
  <div class="px-4 pt-3">
    <div class="grid grid-cols-4 gap-2">
      <template x-for="ty in cfg.types" :key="ty.key">
        <button class="rounded-xl bg-white border p-2 text-center active:scale-95 transition" @click="call(ty)" :disabled="busy">
          <div class="text-2xl" x-text="ty.icon"></div><div class="text-[11px] font-semibold leading-tight mt-0.5" x-text="ty.label"></div></button>
      </template>
    </div>
    <template x-for="c in st.calls.filter(c=>c.status!=='DONE')" :key="c.at+c.label">
      <div class="mt-2 rounded-xl px-3 py-2 text-sm" :class="c.status==='COMING' ? 'bg-green-50 border border-green-200 text-green-800' : 'bg-amber-50 border border-amber-200 text-amber-800'">
        <span x-text="c.label"></span> · <b x-text="c.status==='COMING' ? (c.by ? c.by+' is coming ✓' : 'Someone is coming ✓') : 'Asked at '+c.at+', please wait…'"></b></div>
    </template>
  </div>
  @endif

  {{-- my order so far --}}
  <template x-if="st.items.length">
    <div class="mx-4 mt-3 rounded-2xl bg-white border p-3">
      <div class="flex items-center"><div class="font-semibold">Your order</div><div class="ml-auto text-sm" x-show="!st.paid">Bill so far <b x-text="'৳'+fmt(st.bill)"></b></div><div class="ml-auto text-sm text-green-700 font-semibold" x-show="st.paid">Paid ✓ Thank you!</div></div>
      <template x-for="(i,n) in st.items" :key="n"><div class="flex items-center gap-2 text-sm py-1 border-b last:border-0">
        <span class="flex-1"><b x-text="i.qty+'×'"></b> <span x-text="i.name"></span></span>
        <span class="text-xs rounded-full px-2 py-0.5" :class="i.state==='NEW' ? 'bg-amber-100 text-amber-800' : (i.state==='READY' ? 'bg-green-100 text-green-800' : 'bg-slate-100 text-slate-600')"
          x-text="i.state==='NEW' ? '🍳 cooking' : (i.state==='READY' ? '✅ ready' : '🍽 served')"></span></div></template>
      <div x-show="st.claim && !st.paid" class="mt-2 rounded-xl bg-green-50 border border-green-200 text-green-800 px-3 py-2 text-sm">📲 <span x-text="st.claim ? st.claim.method+' payment sent (TrxID '+st.claim.trx+') — the cashier will confirm it.' : ''"></span></div>
      <button x-show="cfg.pay.length && st.unclaimed>0 && !st.paid" class="w-full mt-2 rounded-xl bg-pink-600 text-white py-2.5 font-semibold active:scale-95" @click="paySheet=true; payM=cfg.pay[0].key" x-text="'📲 Pay ৳'+fmt(st.unclaimed,0)+' by '+cfg.pay.map(p=>p.label).join(' / ')"></button>
    </div>
  </template>

  {{-- menu --}}
  <div class="px-4 pt-3 grid grid-cols-2 gap-3">
    <template x-for="m in menu.filter(m => !cat || m.category===cat)" :key="m.id">
      <div class="rounded-2xl bg-white border overflow-hidden flex flex-col">
        <template x-if="m.img"><img :src="m.img" class="w-full aspect-[4/3] object-cover bg-slate-100" alt="" loading="lazy"></template>
        <template x-if="!m.img"><div class="w-full aspect-[4/3] bg-gradient-to-br from-orange-100 to-amber-50 grid place-items-center text-4xl">🍛</div></template>
        <div class="p-2.5 flex-1 flex flex-col">
          <div class="font-semibold text-sm leading-tight" x-text="m.name"></div>
          <div class="text-xs text-slate-400" x-text="m.name_bn"></div>
          <div class="mt-auto pt-2 flex items-center">
            <b class="text-orange-700" x-text="'৳'+fmt(m.price, 0)"></b>
            @if($cfg['order'])
            <button x-show="!qty(m.id)" class="ml-auto rounded-full bg-orange-600 text-white w-8 h-8 text-xl leading-none active:scale-90" @click="add(m)">+</button>
            <div x-show="qty(m.id)" x-cloak class="ml-auto flex items-center gap-2"><button class="rounded-full border w-7 h-7 leading-none" @click="dec(m)">−</button><b x-text="qty(m.id)"></b><button class="rounded-full bg-orange-600 text-white w-7 h-7 leading-none" @click="add(m)">+</button></div>
            @endif
          </div>
        </div>
      </div>
    </template>
  </div>
</div>

{{-- cart bar --}}
@if($cfg['order'])
<div x-show="cart.length" x-cloak class="fixed bottom-0 inset-x-0 z-30 p-3" style="padding-bottom:max(12px, env(safe-area-inset-bottom))">
  <button class="max-w-xl mx-auto w-full rounded-2xl bg-orange-600 text-white px-4 py-3.5 flex items-center shadow-lg" @click="sheet=true">
    <span class="rounded-lg bg-white/20 px-2 py-0.5 font-bold" x-text="count()"></span><span class="ml-3 font-semibold">View order</span><span class="ml-auto font-bold" x-text="'৳'+fmt(total())"></span></button>
</div>
<div x-show="sheet" x-cloak class="fixed inset-0 z-40 bg-black/40" @click.self="sheet=false">
  <div class="absolute bottom-0 inset-x-0 max-w-xl mx-auto bg-white rounded-t-3xl p-4 max-h-[85vh] overflow-y-auto" style="padding-bottom:max(16px, env(safe-area-inset-bottom))">
    <div class="w-10 h-1.5 bg-slate-200 rounded-full mx-auto mb-3"></div>
    <div class="font-bold text-lg mb-2">Table {{ $t->name }} · your order</div>
    <template x-for="l in cart" :key="l.id"><div class="flex items-center gap-2 py-2 border-b">
      <div class="flex-1"><div class="font-semibold text-sm" x-text="l.name"></div><div class="text-xs text-slate-500" x-text="'৳'+fmt(l.price,0)+' each'"></div></div>
      <button class="rounded-full border w-7 h-7" @click="dec(l)">−</button><b x-text="l.qty" class="w-5 text-center"></b><button class="rounded-full bg-orange-600 text-white w-7 h-7" @click="add(l)">+</button>
      <div class="w-16 text-right text-sm font-semibold" x-text="'৳'+fmt(l.qty*l.price,0)"></div></div></template>
    <div class="text-sm mt-3 space-y-1">
      <div class="flex justify-between"><span>Subtotal</span><span x-text="'৳'+fmt(sub())"></span></div>
      <div class="flex justify-between" x-show="cfg.sc>0"><span x-text="'Service '+cfg.sc+'%'"></span><span x-text="'৳'+fmt(sc())"></span></div>
      <div class="flex justify-between"><span x-text="'VAT '+cfg.vat+'%'"></span><span x-text="'৳'+fmt(vat())"></span></div>
      <div class="flex justify-between font-bold text-base border-t pt-1"><span>Total</span><span x-text="'৳'+fmt(total())"></span></div>
    </div>
    <textarea x-model="note" rows="2" class="w-full mt-3 rounded-xl border px-3 py-2 text-sm" placeholder="Anything for the kitchen? (less spicy, no onion…)"></textarea>
    @if($cfg['phone'])<input x-model="phone" inputmode="tel" class="w-full mt-2 rounded-xl border px-3 py-2 text-sm" placeholder="Your mobile number *">@endif
    <button class="w-full mt-3 rounded-2xl bg-orange-600 text-white py-3.5 font-bold text-lg disabled:opacity-50" @click="send()" :disabled="busy || !cart.length" x-text="busy ? 'Sending…' : '🍳 Send to kitchen'"></button>
    <div class="text-[11px] text-slate-500 text-center mt-2">You pay at the counter or to the waiter when you finish{{ count($cfg['pay']) ? ' — or by '.collect($cfg['pay'])->pluck('label')->join(' / ').' from this page' : '' }}.</div>
  </div>
</div>
@endif

<div x-show="paySheet" x-cloak class="fixed inset-0 z-40 bg-black/40" @click.self="paySheet=false">
  <div class="absolute bottom-0 inset-x-0 max-w-xl mx-auto bg-white rounded-t-3xl p-4 max-h-[90vh] overflow-y-auto" style="padding-bottom:max(16px, env(safe-area-inset-bottom))">
    <div class="w-10 h-1.5 bg-slate-200 rounded-full mx-auto mb-3"></div>
    <div class="font-bold text-lg">Pay <span x-text="'৳'+fmt(st.unclaimed,0)"></span> · Table {{ $t->name }}</div>
    <div class="flex gap-2 mt-3">
      <template x-for="p in cfg.pay" :key="p.key"><button class="flex-1 rounded-xl border-2 py-2 font-bold" :class="payM===p.key ? (p.key==='BKASH' ? 'border-pink-600 bg-pink-50 text-pink-700' : 'border-orange-600 bg-orange-50 text-orange-700') : 'border-slate-200'" @click="payM=p.key" x-text="p.label"></button></template>
    </div>
    <template x-for="p in cfg.pay.filter(p=>p.key===payM)" :key="'s'+p.key"><div class="text-center mt-3">
      <template x-if="p.qr"><img :src="p.qr" class="mx-auto w-56 h-56 object-contain rounded-xl border bg-white" alt="QR"></template>
      <div class="text-sm text-slate-600 mt-2" x-show="p.qr">Scan with the <b x-text="p.label"></b> app<span x-show="p.number">, or</span></div>
      <div x-show="p.number" class="mt-1">Send money / pay to <b class="font-mono text-lg" x-text="p.number"></b> <button class="text-xs text-blue-700 underline" @click="navigator.clipboard && navigator.clipboard.writeText(p.number); say('Number copied')">copy</button></div>
      <div class="text-sm mt-1">Amount: <b x-text="'৳'+fmt(st.unclaimed,0)"></b></div>
    </div></template>
    @if($cfg['pay_note'])<div class="text-xs text-slate-500 text-center mt-1">{{ $cfg['pay_note'] }}</div>@endif
    <input x-model="trx" class="w-full mt-3 rounded-xl border px-3 py-2.5 text-sm font-mono uppercase" placeholder="Transaction ID (TrxID) from the SMS *" maxlength="24">
    <input x-model="payPhone" inputmode="tel" class="w-full mt-2 rounded-xl border px-3 py-2.5 text-sm" placeholder="Your bKash / Nagad number (optional)">
    <button class="w-full mt-3 rounded-2xl bg-pink-600 text-white py-3.5 font-bold text-lg disabled:opacity-50" @click="pay()" :disabled="busy || trx.trim().length<6" x-text="busy ? 'Sending…' : '✓ I have paid'"></button>
    <div class="text-[11px] text-slate-500 text-center mt-2">The cashier checks the payment and closes your bill. You can also pay at the counter.</div>
  </div>
</div>

<div x-show="toast" x-cloak x-transition class="fixed top-4 inset-x-4 z-50 max-w-md mx-auto rounded-xl px-4 py-3 text-sm font-semibold shadow-lg" :class="err ? 'bg-red-600 text-white' : 'bg-green-600 text-white'" x-text="toast"></div>

<script>
function qrMenu(){ return {
  menu: @json($menu), cfg: @json($cfg), cats: [], cat: '', cart: [], sheet: false, note: '', phone: '', busy: false, toast: '', err: false,
  st: { items: [], calls: [], bill: 0, paid: false, claim: null, unclaimed: 0 }, paySheet: false, payM: '', trx: '', payPhone: '',
  init(){ this.cats=[...new Set(this.menu.map(m=>m.category))]; this.poll(); setInterval(()=>this.poll(), 10000) },
  fmt(n, d=2){ return (+n||0).toLocaleString('en-US',{minimumFractionDigits:d,maximumFractionDigits:d}) },
  qty(id){ return this.cart.find(l=>l.id===id)?.qty || 0 }, count(){ return this.cart.reduce((s,l)=>s+l.qty,0) },
  add(m){ const l=this.cart.find(x=>x.id===m.id); if(this.count()>=this.cfg.max) return this.say('Max '+this.cfg.max+' portions per order', true); l ? l.qty++ : this.cart.push({id:m.id,name:m.name,price:m.price,qty:1}) },
  dec(m){ const i=this.cart.findIndex(x=>x.id===m.id); if(i<0) return; this.cart[i].qty>1 ? this.cart[i].qty-- : this.cart.splice(i,1); if(!this.cart.length) this.sheet=false },
  sub(){ return this.cart.reduce((s,l)=>s+l.qty*l.price,0) }, sc(){ return Math.round(this.sub()*this.cfg.sc)/100 }, vat(){ return Math.round((this.sub()+this.sc())*this.cfg.vat)/100 },
  total(){ return Math.round((this.sub()+this.sc()+this.vat())*100)/100 },
  say(m, err=false){ this.toast=m; this.err=err; clearTimeout(this._t); this._t=setTimeout(()=>this.toast='', 3500) },
  async post(url, body){ this.busy=true; try{
      const r=await fetch(url,{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content},body:JSON.stringify(body)});
      const j=await r.json().catch(()=>({message: r.status===429 ? 'Too many requests, please wait a minute.' : (r.status===419 ? 'Please refresh the page.' : 'Something went wrong')}));
      if(!r.ok){ this.say(j.message||'Something went wrong', true); return null } return j
    } catch(e){ this.say('No internet. Please try again or call a waiter.', true); return null } finally{ this.busy=false } },
  async send(){ const j=await this.post('{{ route('qr.order', $t->qr_token) }}',{lines:this.cart.map(l=>({item_id:l.id,qty:l.qty})),note:this.note,customer_phone:this.phone});
    if(j){ this.cart=[]; this.note=''; this.sheet=false; this.apply(j); this.say('✓ '+j.message); window.scrollTo({top:0,behavior:'smooth'}) } },
  async call(ty){ let note=null; if(ty.key==='OTHER'){ note=prompt('What do you need?'); if(!note) return }
    const j=await this.post('{{ route('qr.call', $t->qr_token) }}',{type:ty.key,note}); if(j){ this.apply(j); this.say('✓ '+ty.label+': '+j.message) } },
  apply(j){ this.st={items:j.items||[],calls:j.calls||[],bill:j.bill||0,paid:!!j.paid,claim:j.claim||null,unclaimed:j.unclaimed||0} },
  async pay(){ const j=await this.post('{{ route('qr.pay', $t->qr_token) }}',{method:this.payM,trx:this.trx,phone:this.payPhone});
    if(j){ this.paySheet=false; this.trx=''; this.apply(j); this.say('✓ '+j.message) } },
  async poll(){ try{ const r=await fetch('{{ route('qr.status', $t->qr_token) }}',{headers:{Accept:'application/json'}}); if(r.ok) this.apply(await r.json()) }catch(e){} }
} }
</script>
</body>
</html>
