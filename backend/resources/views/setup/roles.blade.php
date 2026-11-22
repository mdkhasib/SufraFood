@extends('layouts.app')
@section('title', __('Roles & permissions'))
@section('content')
@php($tab = request('tab', 'overview'))
@php($modules = \App\Support\Features::modules())
<div x-data="{tab: '{{ $tab }}'}">
<div class="flex flex-wrap items-center gap-2 mb-4">
  <div class="flex rounded-lg border overflow-hidden text-sm">
    <button type="button" class="px-3 py-1.5" :class="tab==='overview' ? 'bg-slate-800 text-white' : 'bg-white'" @click="tab='overview'">{{ __('Overview: who has what') }}</button>
    <button type="button" class="px-3 py-1.5" :class="tab==='edit' ? 'bg-slate-800 text-white' : 'bg-white'" @click="tab='edit'">{{ __('Edit all permissions') }}</button>
    <button type="button" class="px-3 py-1.5" :class="tab==='roles' ? 'bg-slate-800 text-white' : 'bg-white'" @click="tab='roles'">{{ __('Roles') }} ({{ $roles->count() }})</button>
  </div>
  <p class="text-xs text-slate-500 flex-1">{{ __('Greyed modules/permissions belong to features the Super Admin has switched OFF — they have no effect until switched on.') }}</p>
</div>

{{-- ============ OVERVIEW: role x module ============ --}}
<div x-show="tab==='overview'" class="card overflow-auto">
  <table class="t text-xs">
    <thead class="sticky top-0 z-10"><tr>
      <th class="bg-slate-50 sticky left-0 z-20 min-w-[170px]">{{ __('Role') }}</th>
      @foreach($perms as $group => $list)
        @php($gOn = $list->contains('on', true))
        <th class="text-center min-w-[84px] {{ $gOn ? '' : 'opacity-40' }}">{{ __($group) }}<div class="font-normal normal-case text-slate-400">{{ $list->count() }} {{ __('perms') }}</div></th>
      @endforeach
    </tr></thead>
    @foreach($roles as $role)
      @php($have = $matrix[$role->id] ?? [])
      <tr>
        <td class="sticky left-0 bg-white"><div class="font-semibold text-sm">{{ $role->name }}</div><div class="text-slate-400">{{ $role->tier }} · {{ $role->scope }} · {{ $role->users_count }} {{ __('people') }}</div></td>
        @foreach($perms as $group => $list)
          @php($n = $list->filter(fn ($p) => in_array($p['key'], $have))->count())
          @php($gOn = $list->contains('on', true))
          <td class="text-center {{ $gOn ? '' : 'opacity-40' }}">
            @if($n === 0)<span class="text-slate-300">—</span>
            @else
              <span class="inline-block rounded-full px-2 py-0.5 font-semibold {{ $n === $list->count() ? 'bg-green-600 text-white' : 'bg-green-100 text-green-800' }}"
                    title="{{ $list->filter(fn ($p) => in_array($p['key'], $have))->pluck('label')->join(', ') }}">{{ $n === $list->count() ? __('Full') : $n.'/'.$list->count() }}</span>
            @endif
          </td>
        @endforeach
      </tr>
    @endforeach
  </table>
  <div class="p-3 text-xs text-slate-500 border-t">{{ __('Hover a cell to see the exact permissions. “Full” = every permission in that module.') }}</div>
</div>

{{-- ============ EDIT: every role x every permission on one page ============ --}}
<form x-show="tab==='edit'" x-cloak method="post" action="{{ route('setup.roles.save') }}" id="permform" x-data="{q:''}">@csrf
  <div class="flex flex-wrap items-center gap-2 mb-2">
    <input x-model="q" class="inp !w-64 !py-1.5" placeholder="{{ __('Filter permissions…') }}">
    <span class="text-xs text-slate-500">{{ __('Tick boxes, then Save once. Column header box = whole role; row box = every role; module row = that module for one role.') }}</span>
    <button class="btn btn-primary ml-auto">{{ __('Save permissions') }}</button>
  </div>
  <div class="card overflow-auto max-h-[72vh]">
  <table class="t text-xs">
    <thead class="sticky top-0 z-20"><tr>
      <th class="bg-slate-50 sticky left-0 z-30 min-w-[230px]">{{ __('Permission') }}</th>
      @foreach($roles as $role)
        <th class="text-center min-w-[78px] align-bottom">
          <div class="leading-tight">{{ $role->name }}</div>
          <div class="font-normal normal-case text-slate-400">{{ $role->scope }}</div>
          <input type="checkbox" class="mt-1" title="{{ __('All permissions for this role') }}" onchange="permAll(this, '[data-role=&quot;{{ $role->id }}&quot;]')">
        </th>
      @endforeach
    </tr></thead>
    @foreach($perms as $group => $list)
      @php($gOn = $list->contains('on', true))
      <tbody x-data="{open:true}">
      <tr class="bg-orange-50 {{ $gOn ? '' : 'opacity-50' }}">
        <td class="sticky left-0 bg-orange-50 font-semibold z-10">
          <button type="button" @click="open=!open" class="mr-1" x-text="open ? '▾' : '▸'"></button>{{ __($group) }}
          @unless($gOn)<span class="badge bg-slate-200 text-slate-600 ml-1">{{ __('feature off') }}</span>@endunless
        </td>
        @foreach($roles as $role)
          <td class="text-center"><input type="checkbox" title="{{ $group }} — {{ $role->name }}" onchange="permAll(this, '[data-role=&quot;{{ $role->id }}&quot;][data-group=&quot;{{ \Illuminate\Support\Str::slug($group) }}&quot;]')"></td>
        @endforeach
      </tr>
      @foreach($list as $p)
        <tr x-show="open && (!q || @js(strtolower($p['label'].' '.$p['key'].' '.$group)).includes(q.toLowerCase()))" class="{{ $p['on'] ? '' : 'opacity-40' }} hover:bg-slate-50">
          <td class="sticky left-0 bg-white z-10">
            <label class="flex items-start gap-2"><input type="checkbox" class="mt-0.5" title="{{ __('All roles') }}" onchange="permAll(this, '[data-perm=&quot;{{ $p['key'] }}&quot;]')">
              <span>{{ __($p['label']) }} <span class="block text-[10px] text-slate-400">{{ $p['key'] }}</span></span></label>
          </td>
          @foreach($roles as $role)
            <td class="text-center"><input type="checkbox" name="perm[{{ $role->id }}][]" value="{{ $p['key'] }}" data-role="{{ $role->id }}" data-perm="{{ $p['key'] }}" data-group="{{ \Illuminate\Support\Str::slug($group) }}" @checked(in_array($p['key'], $matrix[$role->id] ?? []))></td>
          @endforeach
        </tr>
      @endforeach
      </tbody>
    @endforeach
  </table>
  </div>
  <button class="btn btn-primary mt-3">{{ __('Save permissions') }}</button>
</form>

{{-- ============ ROLES: create / edit / delete ============ --}}
<div x-show="tab==='roles'" x-cloak>
  <div class="card overflow-x-auto mb-4">
  <table class="t text-sm">
    <tr><th>{{ __('Role') }}</th><th>{{ __('Tier') }}</th><th>{{ __('Data scope') }}</th><th>{{ __('People') }}</th><th></th></tr>
    @foreach($roles as $role)
    <tr>
      <td colspan="5" class="!p-0">
        <form method="post" action="{{ route('setup.roles.update', $role) }}" class="grid grid-cols-[1fr_120px_130px_70px_auto] gap-2 items-center px-3 py-2">@csrf
          <div><input name="name" value="{{ $role->name }}" class="inp !py-1"><div class="text-[11px] text-slate-400">{{ $role->key }}</div></div>
          <select name="tier" class="inp !py-1">@foreach(['T1','T2','T3','T4','FUNCTION','OFFICE'] as $t)<option @selected($role->tier===$t)>{{ $t }}</option>@endforeach</select>
          <select name="scope" class="inp !py-1">@foreach(['SELF','TEAM','LOCATION','ALL'] as $t)<option @selected($role->scope===$t)>{{ $t }}</option>@endforeach</select>
          <div class="text-center">{{ $role->users_count }}</div>
          <div class="flex gap-1"><button class="btn btn-dark btn-sm">{{ __('Save') }}</button>
            @if(!$role->users_count)<button type="submit" form="delrole-{{ $role->id }}" class="btn btn-light btn-sm text-red-600" onclick="return confirm('{{ __('Delete this role?') }}')">{{ __('Delete') }}</button>@endif</div>
        </form>
        @if(!$role->users_count)<form id="delrole-{{ $role->id }}" method="post" action="{{ route('setup.roles.delete', $role) }}">@csrf @method('DELETE')</form>@endif
      </td>
    </tr>
    @endforeach
  </table>
  </div>
  <form method="post" action="{{ route('setup.roles.new') }}" class="card p-4 grid sm:grid-cols-5 gap-3 items-end">@csrf
    <div><label class="lbl">{{ __('Key') }}</label><input name="key" class="inp" placeholder="pastry_chef"></div>
    <div><label class="lbl">{{ __('Name') }}</label><input name="name" class="inp" placeholder="Pastry Chef"></div>
    <div><label class="lbl">{{ __('Tier') }}</label><select name="tier" class="inp">@foreach(['T1','T2','T3','T4','FUNCTION','OFFICE'] as $t)<option>{{ $t }}</option>@endforeach</select></div>
    <div><label class="lbl">{{ __('Data scope') }}</label><select name="scope" class="inp">@foreach(['SELF','TEAM','LOCATION','ALL'] as $t)<option>{{ $t }}</option>@endforeach</select></div>
    <button class="btn btn-dark">+ {{ __('New role') }}</button>
  </form>
  <p class="text-xs text-slate-500 mt-2">{{ __('Scope: SELF = only own data · TEAM = people below them · LOCATION = their whole branch · ALL = whole company.') }}</p>
</div>
</div>
<script>
  function permAll(src, sel) { document.querySelectorAll('#permform input[type=checkbox]' + sel).forEach(cb => { if (cb.closest('tr').style.display !== 'none') cb.checked = src.checked; }); }
</script>
@endsection
