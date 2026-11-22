@extends('layouts.app')
@section('title', ($row->exists ? __('Edit') : __('Add')).' · '.__($cfg['title']))
@section('content')
<form method="post" action="{{ $row->exists ? route('crud.update', [$cfg['res'], $row->id]) : route('crud.store', $cfg['res']) }}" class="card p-5 max-w-4xl" x-data>
  @csrf @if($row->exists) @method('PUT') @endif
  <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
  @foreach($fields as $k => $f)
    @php($val = old($k, $row->$k instanceof \Carbon\Carbon ? $row->$k->format('Y-m-d') : $row->$k))
    <div class="{{ $f[1]==='textarea' ? 'sm:col-span-2 lg:col-span-3' : '' }}">
      <label class="lbl">{{ $f[0] }} @if(($f[2] ?? null)==='required')<span class="text-red-500">*</span>@endif</label>
      @switch($f[1])
        @case('select')
          <select name="{{ $k }}" class="inp"><option value="">—</option>@foreach($f[2] as $ok=>$ov)<option value="{{ $ok }}" @selected((string)$val===(string)$ok)>{{ $ov }}</option>@endforeach</select>@break
        @case('bool')
          <label class="flex items-center gap-2 mt-2"><input type="checkbox" name="{{ $k }}" value="1" @checked($row->exists ? $val : true)> {{ __('Yes') }}</label>@break
        @case('textarea')<textarea name="{{ $k }}" class="inp" rows="3">{{ $val }}</textarea>@break
        @case('password')<input type="password" name="{{ $k }}" class="inp" placeholder="{{ $row->exists ? '••••••' : 'default 123456' }}">@break
        @case('time')<input type="time" name="{{ $k }}" value="{{ $val ? substr($val,0,5) : '' }}" class="inp">@break
        @case('date')<input type="date" name="{{ $k }}" value="{{ $val }}" class="inp">@break
        @case('number')<input type="number" step="any" name="{{ $k }}" value="{{ $val }}" class="inp">@break
        @default <input name="{{ $k }}" value="{{ $val }}" class="inp">
      @endswitch
    </div>
  @endforeach
  @if($cfg['res']==='users' && auth()->user()->is_super_admin)
    <div><label class="lbl">Company Admin</label><label class="flex items-center gap-2 mt-2"><input type="checkbox" name="is_company_admin" value="1" @checked($row->is_company_admin)> Gives admin features (Super Admin only)</label></div>
  @endif
  </div>
  @if($cfg['res']==='locations')
    <div class="mt-4 text-xs text-slate-500" x-data="{msg:''}">
      <button type="button" class="btn btn-light btn-sm" @click="navigator.geolocation.getCurrentPosition(p=>{document.querySelector('[name=lat]').value=p.coords.latitude.toFixed(7);document.querySelector('[name=lng]').value=p.coords.longitude.toFixed(7);msg='Set to your current position (±'+Math.round(p.coords.accuracy)+' m). Save to apply.'},e=>msg='Location blocked: '+e.message,{enableHighAccuracy:true})">📍 Use my current location as this place's GPS area</button>
      <span x-text="msg" class="ml-2"></span>
    </div>
  @endif
  <div class="mt-5 flex gap-2"><button class="btn btn-primary">{{ __('Save') }}</button><a href="{{ route('crud.index', $cfg['res']) }}" class="btn btn-light">{{ __('Cancel') }}</a></div>
</form>
@endsection
