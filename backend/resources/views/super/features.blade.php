@extends('layouts.app')
@section('title', __('Modules & features'))
@section('content')
<div class="card p-4 mb-4 flex flex-wrap items-center gap-4">
  <div class="text-sm">{{ __('Modules') }}: <b class="text-green-700">ON {{ $moduleCounts['ON'] ?? 0 }}</b> · <b class="text-slate-500">OFF {{ $moduleCounts['OFF'] ?? 0 }}</b>
    &nbsp;|&nbsp; {{ __('Features') }}: <b class="text-green-700">ON {{ $counts['ON'] ?? 0 }}</b> · <b class="text-amber-700">PILOT {{ $counts['PILOT'] ?? 0 }}</b> · <b class="text-slate-500">OFF {{ $counts['OFF'] ?? 0 }}</b></div>
  <p class="text-xs text-slate-500 flex-1">{{ __('Step 1: switch a module ON. Step 2: switch its features ON one by one (or PILOT at chosen locations). A module that is OFF hides all its features everywhere — web menu, mobile app and API — for everyone, including you. Data is kept.') }}</p>
  <form method="post" action="{{ route('super.features.bulk') }}" onsubmit="return confirm('{{ __('Change ALL modules and features?') }}')" class="flex gap-2">@csrf
    <button name="status" value="ON" class="btn btn-light btn-sm">{{ __('All ON (demo)') }}</button><button name="status" value="OFF" class="btn btn-light btn-sm">{{ __('All OFF') }}</button></form>
</div>

<div class="grid lg:grid-cols-[220px_1fr] gap-4">
  <nav class="card p-2 self-start lg:sticky lg:top-4 text-sm">
    @foreach($modules as $name => $m)
      @continue(! isset($features[$name]))
      <a href="#mod-{{ \Illuminate\Support\Str::slug($name) }}" class="flex items-center gap-2 rounded-lg px-2 py-1.5 hover:bg-slate-50">
        <span class="h-2.5 w-2.5 rounded-full {{ $m->status==='ON' ? 'bg-green-500' : 'bg-slate-300' }}"></span>
        <span class="flex-1 {{ $m->status==='ON' ? '' : 'text-slate-400' }}">{{ app()->getLocale()==='bn' && $m->name_bn ? $m->name_bn : $name }}</span>
        <span class="text-[11px] text-slate-400">{{ $features[$name]->where('status','!=','OFF')->count() }}/{{ $features[$name]->count() }}</span>
      </a>
    @endforeach
  </nav>

  <div class="space-y-4">
  @foreach($modules as $name => $m)
    @continue(! isset($features[$name]))
    @php($on = $m->status === 'ON')
    <div class="card overflow-hidden" id="mod-{{ \Illuminate\Support\Str::slug($name) }}">
      <div class="px-4 py-3 border-b flex flex-wrap items-center gap-3 {{ $on ? 'bg-green-50' : 'bg-slate-100' }}">
        <div class="flex-1 min-w-[200px]">
          <div class="font-semibold">{{ $name }} @if($m->name_bn)<span class="text-slate-400 font-normal">· {{ $m->name_bn }}</span>@endif</div>
          <div class="text-xs text-slate-500">{{ $m->description }}</div>
        </div>
        @if($m->exists)
        <form method="post" action="{{ route('super.modules.update', $m) }}" class="flex items-center gap-2">@csrf
          @if($on)
            <span class="badge bg-green-600 text-white">{{ __('MODULE ON') }}</span>
            <button name="status" value="OFF" class="btn btn-light btn-sm" onclick="return confirm('{{ __('Switch the whole module OFF? All its features disappear for everyone.') }}')">{{ __('Turn module OFF') }}</button>
          @else
            <span class="badge bg-slate-500 text-white">{{ __('MODULE OFF') }}</span>
            <label class="text-xs flex items-center gap-1"><input type="checkbox" name="with_features" value="1"> {{ __('also turn all its features ON') }}</label>
            <button name="status" value="ON" class="btn btn-green btn-sm">{{ __('Turn module ON') }}</button>
          @endif
        </form>
        @endif
      </div>
      @if($name === 'Company settings' && $m->exists)
        <form method="post" action="{{ route('super.modules.update', $m) }}" class="px-4 py-3 border-b bg-amber-50 flex flex-wrap items-center gap-3 text-sm">@csrf
          <div class="flex-1">{{ __('Who can open Company settings?') }} <b>{{ $m->admin_access ? __('Super Admin and Company Admins') : __('Super Admin only') }}</b></div>
          <input type="hidden" name="admin_access" value="{{ $m->admin_access ? 0 : 1 }}">
          <button class="btn btn-dark btn-sm">{{ $m->admin_access ? __('Take it back (Super Admin only)') : __('Allow Company Admins') }}</button>
        </form>
      @endif
      <div class="{{ $on ? '' : 'opacity-50' }}">
      @foreach($features[$name] as $f)
      <form method="post" action="{{ route('super.features.update', $f) }}" class="px-4 py-3 border-b last:border-0" x-data="{s:'{{ $f->status }}'}">@csrf
        <div class="flex flex-wrap items-center gap-3">
          <div class="flex-1 min-w-[200px]">
            <div class="font-medium text-sm">{{ $f->name }}</div>
            <div class="text-[11px] text-slate-400">{{ $f->key }} @if($f->depends_on) · {{ __('needs') }} {{ implode(', ', $f->depends_on) }} @endif</div>
          </div>
          <div class="flex rounded-lg border overflow-hidden text-xs font-semibold">
            @foreach(['OFF'=>'bg-slate-600','PILOT'=>'bg-amber-500','ON'=>'bg-green-600'] as $st=>$cls)
              <label class="px-2.5 py-1 cursor-pointer" :class="s==='{{ $st }}' ? '{{ $cls }} text-white' : 'bg-white'"><input type="radio" name="status" value="{{ $st }}" class="hidden" x-model="s">{{ $st }}</label>
            @endforeach
          </div>
          <button class="btn btn-dark btn-sm" x-show="s!=='{{ $f->status }}' || s==='PILOT'">{{ __('Save') }}</button>
        </div>
        <div x-show="s==='PILOT'" x-cloak class="mt-2 flex flex-wrap gap-2 text-xs">
          @foreach($locations as $l)
            <label class="flex items-center gap-1 border rounded px-2 py-0.5"><input type="checkbox" name="locations[]" value="{{ $l->id }}" @checked($f->locations->contains($l->id))> {{ $l->code }}</label>
          @endforeach
        </div>
        @if($f->status==='PILOT')<div class="text-[11px] text-amber-700 mt-1">{{ __('Pilot at') }}: {{ $f->locations->pluck('name')->join(', ') }}</div>@endif
        @if(!$on && $f->status!=='OFF')<div class="text-[11px] text-slate-500 mt-1">{{ __('Set to') }} {{ $f->status }}, {{ __('but hidden while the module is OFF') }}</div>@endif
      </form>
      @endforeach
      </div>
    </div>
  @endforeach
  </div>
</div>
@endsection
