@extends('layouts.app')
@section('title', ($row->exists ? __('Edit') : __('Add')).' · '.__($cfg['title']))
@section('content')
<form method="post" action="{{ $row->exists ? route('crud.update', [$cfg['res'], $row->id]) : route('crud.store', $cfg['res']) }}" enctype="multipart/form-data" class="card p-5 max-w-4xl" x-data>
  @csrf @if($row->exists) @method('PUT') @endif
  <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
  @foreach($fields as $k => $f)
    @php($val = old($k, $row->$k instanceof \Carbon\Carbon ? $row->$k->format('Y-m-d') : $row->$k))
    <div class="{{ in_array($f[1], ['textarea', 'image']) ? 'sm:col-span-2 lg:col-span-3' : '' }}">
      <label class="lbl">{{ $f[0] }} @if(($f[2] ?? null)==='required')<span class="text-red-500">*</span>@endif</label>
      @switch($f[1])
        @case('select')
          <select name="{{ $k }}" class="inp"><option value="">—</option>@foreach($f[2] as $ok=>$ov)<option value="{{ $ok }}" @selected((string)$val===(string)$ok)>{{ $ov }}</option>@endforeach</select>@break
        @case('bool')
          <label class="flex items-center gap-2 mt-2"><input type="checkbox" name="{{ $k }}" value="1" @checked($row->exists ? $val : true)> {{ __('Yes') }}</label>@break
        @case('image')
          <div class="flex items-center gap-3">
            <div class="h-20 w-20 shrink-0 rounded-xl border bg-slate-50 grid place-items-center overflow-hidden">@if($row->$k)<img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($row->$k) }}" class="h-full w-full object-cover" alt="">@else<span class="text-2xl text-slate-300">📷</span>@endif</div>
            <div class="text-xs space-y-1"><input type="file" name="{{ $k }}" accept="image/png,image/jpeg,image/webp" class="text-sm">
              <div class="text-slate-400">{{ __('JPG / PNG / WebP, max 3 MB. A square photo looks best.') }}</div>
              @if($row->$k)<label class="flex items-center gap-1 text-red-600"><input type="checkbox" name="{{ $k }}__remove" value="1"> {{ __('Remove photo') }}</label>@endif</div>
          </div>@break
        @case('suggest')
          <input name="{{ $k }}" value="{{ $val }}" class="inp" list="dl-{{ $k }}" autocomplete="off" placeholder="{{ __('choose or type a new one') }}">
          <datalist id="dl-{{ $k }}">@foreach($f[2] as $opt)<option value="{{ $opt }}">@endforeach</datalist>
          <div class="flex flex-wrap gap-1 mt-1">@foreach(array_slice($f[2], 0, 10) as $opt)<button type="button" class="text-[11px] rounded-full border px-2 py-0.5 hover:bg-orange-50" onclick="this.closest('div').previousElementSibling.previousElementSibling.value=this.textContent">{{ $opt }}</button>@endforeach</div>@break
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
