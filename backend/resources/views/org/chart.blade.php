@extends('layouts.app')
@section('title', __('Org chart'))
@section('content')
@php($me = auth()->user())
<style>
  .org ul{padding-top:22px;position:relative;display:flex;justify-content:center;margin:0}
  .org li{list-style:none;position:relative;padding:22px 6px 0;text-align:center;display:flex;flex-direction:column;align-items:center}
  .org li::before,.org li::after{content:'';position:absolute;top:0;right:50%;border-top:2px solid #cbd5e1;width:50%;height:22px}
  .org li::after{right:auto;left:50%;border-left:2px solid #cbd5e1}
  .org li:only-child::before,.org li:only-child::after{display:none}
  .org li:only-child{padding-top:0}
  .org li:first-child::before,.org li:last-child::after{border:0 none}
  .org li:last-child::before{border-right:2px solid #cbd5e1;border-radius:0 8px 0 0}
  .org li:first-child::after{border-radius:8px 0 0 0}
  .org ul ul::before{content:'';position:absolute;top:0;left:50%;border-left:2px solid #cbd5e1;width:0;height:22px}
  .org > ul{padding-top:0}
  .org-hit{outline:3px solid #f97316;outline-offset:2px}
</style>

<div class="card p-3 mb-3 flex flex-wrap items-center gap-2" x-data>
  <div class="flex rounded-lg border overflow-hidden text-sm">
    @foreach(['chart'=>'Chart','list'=>'List'] as $k=>$l)<a href="{{ request()->fullUrlWithQuery(['view'=>$k]) }}" class="px-3 py-1.5 {{ $view===$k ? 'bg-slate-800 text-white' : 'bg-white' }}">{{ __($l) }}</a>@endforeach
  </div>
  @if($locations->count())
    <form class="flex gap-2"><input type="hidden" name="view" value="{{ $view }}">
      <select name="location_id" class="inp !py-1.5 !w-auto" onchange="this.form.submit()"><option value="">{{ __('Whole company') }}</option>@foreach($locations as $l)<option value="{{ $l->id }}" @selected($loc==$l->id)>{{ $l->name }}</option>@endforeach</select></form>
  @endif
  @if($rootUser)<a href="{{ route('org', ['view'=>$view]) }}" class="badge bg-orange-100 text-orange-800">{{ __('Showing under') }} {{ $rootUser->name }} &times;</a>@endif
  <input id="orgq" class="inp !py-1.5 !w-56" placeholder="{{ __('Find a person or team…') }}" oninput="orgFind(this.value)">
  @if($view==='chart')
    <div class="flex gap-1 ml-auto text-sm"><button type="button" class="btn btn-light btn-sm" onclick="orgZoom(-0.1)">−</button><button type="button" class="btn btn-light btn-sm" onclick="orgZoom(0)">100%</button><button type="button" class="btn btn-light btn-sm" onclick="orgZoom(0.1)">+</button></div>
  @endif
  <span class="text-xs text-slate-500 {{ $view==='chart' ? '' : 'ml-auto' }}">{{ $total }} {{ __('people') }}</span>
  @if($me->hasPerm('teams.view'))<a href="{{ route('teams') }}" class="btn btn-dark btn-sm">{{ __('Teams') }}</a>@endif
</div>
<p class="text-xs text-slate-500 mb-3">{{ __('Lines show who reports to whom. Card colour = team. Use “+ Task” to give work straight to anyone under you, “Reports to” to move a person, and ⌖ to open just that part of the tree.') }}</p>

@if(!$tree)
  <div class="card p-8 text-center text-slate-500">{{ __('Nobody to show.') }}</div>
@elseif($view==='list')
  <div class="card p-3">@foreach($tree as $n) @include('org._row', ['n' => $n, 'depth' => 0]) @endforeach</div>
@else
  <div id="orgbox" class="card overflow-auto p-4" style="max-height:78vh">
    <div id="orgzoom" class="org inline-block min-w-full origin-top-left">
      <ul>@foreach($tree as $n) @include('org._node', ['n' => $n, 'depth' => 0]) @endforeach</ul>
    </div>
  </div>
@endif

@if($me->hasPerm('tasks.assign'))
  @include('tasks._assign')
@endif

@if($me->hasPerm('teams.manage'))
<div x-data="{open:false, uid:null, name:'', manager:''}" @reports-to.window="open=true; uid=$event.detail.id; name=$event.detail.name; manager=$event.detail.manager ?? ''" x-show="open" x-cloak class="fixed inset-0 z-50 bg-black/40 grid place-items-center p-4">
  <form method="post" :action="'{{ url('/org') }}/' + uid + '/manager'" class="card p-5 w-full max-w-md" @click.outside="open=false">@csrf
    <div class="font-semibold text-lg mb-1">{{ __('Who does this person report to?') }}</div>
    <div class="text-sm text-slate-500 mb-3" x-text="name"></div>
    <select name="manager_id" class="inp" x-model="manager">
      <option value="">{{ __('Nobody (top level)') }}</option>
      @foreach($managers as $m)<option value="{{ $m->id }}">{{ $m->name }} — {{ $m->position }} · {{ $m->location?->code }}</option>@endforeach
    </select>
    <p class="text-[11px] text-slate-500 mt-2">{{ __('Their whole team moves with them. Approvals that go to the “direct manager” will follow the new line.') }}</p>
    <div class="mt-4 flex gap-2"><button class="btn btn-primary">{{ __('Save') }}</button><button type="button" class="btn btn-light" @click="open=false">{{ __('Cancel') }}</button></div>
  </form>
</div>
@endif

<script>
  let orgScale = 1;
  // start centred on the top of the tree
  window.addEventListener('load', () => setTimeout(() => { const box = document.getElementById('orgbox'), top = document.querySelector('#orgzoom .org-card'); if (box && top) { const a = top.getBoundingClientRect(), b = box.getBoundingClientRect(); box.scrollLeft += a.left + a.width / 2 - (b.left + box.clientWidth / 2); } }, 120));
  function orgZoom(d) { const el = document.getElementById('orgzoom'); if (!el) return; orgScale = d === 0 ? 1 : Math.min(1.6, Math.max(0.4, orgScale + d)); el.style.transform = 'scale(' + orgScale + ')'; }
  function orgFind(q) {
    q = q.trim().toLowerCase();
    document.querySelectorAll('[data-search]').forEach(el => {
      const hit = q.length > 1 && el.dataset.search.includes(q);
      el.classList.toggle('org-hit', hit);
      if (hit) {
        // open every collapsed parent so the match is visible
        let p = el.parentElement;
        while (p) { if (p._x_dataStack && p._x_dataStack[0] && 'open' in p._x_dataStack[0]) p._x_dataStack[0].open = true; p = p.parentElement; }
      }
    });
    const first = document.querySelector('.org-hit'); if (first) setTimeout(() => first.scrollIntoView({behavior: 'smooth', block: 'center', inline: 'center'}), 50);
  }
</script>
@endsection
