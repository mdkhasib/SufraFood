@extends('layouts.app')
@section('title', __('Company settings'))
@section('content')
@php($super = auth()->user()->is_super_admin)
<div x-data="{tab: '{{ \Illuminate\Support\Str::slug(array_key_first($sections)) }}'}" class="grid lg:grid-cols-[220px_1fr] gap-4">
  <nav class="card p-2 self-start text-sm">
    @foreach($sections as $title => $rows)
      @php($slug = \Illuminate\Support\Str::slug($title))
      <button type="button" class="w-full text-left rounded-lg px-3 py-2" :class="tab==='{{ $slug }}' ? 'bg-orange-50 text-orange-800 font-semibold' : 'hover:bg-slate-50'" @click="tab='{{ $slug }}'">{{ __($title) }}</button>
    @endforeach
    <div class="text-[11px] text-slate-400 px-3 pt-2 border-t mt-2">{{ $super ? __('Company Admins can use this page only if you allow it under Modules & features.') : __('The Super Admin lets Company Admins manage these settings.') }}</div>
  </nav>
  <form method="post" action="{{ route('setup.settings.save') }}" enctype="multipart/form-data" class="card p-5">@csrf
    @foreach($sections as $title => $rows)
      <div x-show="tab==='{{ \Illuminate\Support\Str::slug($title) }}'" @if(!$loop->first) x-cloak @endif>
        <div class="font-semibold text-lg mb-3">{{ __($title) }}</div>
        <div class="grid sm:grid-cols-2 gap-4">
        @foreach($rows as $k => $def)
          @continue(($def[2] ?? null)==='super' && !$super)
          @php($name = str_replace('.', '__', $k))
          @php($val = \App\Support\Features::setting($k))
          <div class="{{ ($def[2] ?? null)==='super' ? 'sm:col-span-2 rounded-lg bg-amber-50 border border-amber-200 p-3' : ($def[1]==='image' ? 'sm:col-span-2' : '') }}">
            <label class="lbl">{{ __($def[0]) }}</label>
            @if($def[1]==='bool')
              <input type="hidden" name="{{ $name }}__present" value="1">
              <label class="flex items-center gap-2"><input type="checkbox" name="{{ $name }}" value="1" @checked((int)$val)> {{ __('Enabled') }}</label>
            @elseif($def[1]==='image')
              @php($logo = \App\Support\Brand::logoUrl())
              <div class="flex items-center gap-3">
                <div class="h-16 w-16 shrink-0 rounded-xl border bg-slate-50 grid place-items-center overflow-hidden">@if($logo)<img src="{{ $logo }}" class="max-h-16 max-w-16 object-contain">@else<span class="font-bold text-brand-700">{{ \App\Support\Brand::initials() }}</span>@endif</div>
                <div class="text-xs space-y-1"><input type="file" name="{{ $name }}" accept="image/png,image/jpeg,image/webp" class="text-sm">
                  <div class="text-slate-400">{{ __('Square or wide logo, max 2 MB. Bills print it in black & white.') }}</div>
                  @if($logo)<label class="flex items-center gap-1 text-red-600"><input type="checkbox" name="{{ $name }}__remove" value="1"> {{ __('Remove logo') }}</label>@endif</div>
              </div>
            @elseif($def[1]==='select')
              <select name="{{ $name }}" class="inp">@foreach($def[2] as $o=>$l)<option value="{{ $o }}" @selected($val==$o)>{{ __($l) }}</option>@endforeach</select>
            @else
              <input type="{{ $def[1]==='time' ? 'time' : ($def[1]==='number' ? 'number' : 'text') }}" step="any" name="{{ $name }}" value="{{ $val }}" class="inp">
            @endif
          </div>
        @endforeach
        </div>
        @if($title === 'Logo & bill (POS)')
          <div class="mt-5 border-t pt-4">
            <div class="flex items-center gap-2 mb-2"><div class="font-semibold">{{ __('Bill preview') }}</div><span class="text-xs text-slate-500">{{ __('Save to update. Uses the latest paid bill, or a sample.') }}</span>
              <a href="{{ route('setup.bill.preview') }}" target="_blank" class="btn btn-light btn-sm ml-auto">🖨 {{ __('Open & test print') }}</a></div>
            <div class="rounded-xl bg-slate-100 p-4 flex justify-center"><iframe src="{{ route('setup.bill.preview') }}" class="bg-white shadow rounded" style="width:{{ \App\Support\Features::setting('receipt.paper')==='A5' ? '560px' : (\App\Support\Features::setting('receipt.paper')==='58' ? '240px' : '330px') }};height:620px;border:0"></iframe></div>
            <div class="text-xs text-slate-500 mt-2">{{ __('Thermal printer tip: in the print window choose your POS printer, paper “80 mm” (or 58 mm), margins “None”, and turn off “Headers and footers”.') }}</div>
          </div>
        @endif
        @if($title === 'Home delivery')
          <div class="mt-5 border-t pt-4">
            <div class="font-semibold">{{ __('Rider bonus per delivery, branch by branch') }}</div>
            <div class="text-xs text-slate-500 mb-2">{{ __('Leave empty to use the default above. The rate at the time of delivery is saved with each delivery.') }}</div>
            <div class="grid sm:grid-cols-2 gap-2">
              @foreach(\App\Models\Location::where('type', 'BRANCH')->orderBy('name')->get() as $b)
                <label class="flex items-center gap-2 rounded-lg border p-2"><span class="flex-1 text-sm">{{ $b->name }}</span><span class="text-slate-400">৳</span><input type="number" step="any" min="0" name="branch_rate[{{ $b->id }}]" value="{{ $b->delivery_rate !== null ? rtrim(rtrim(number_format($b->delivery_rate, 2, '.', ''), '0'), '.') : '' }}" placeholder="{{ (float) \App\Support\Features::setting('delivery.rate_default', 50) }}" class="inp !w-24 !py-1 text-right"></label>
              @endforeach
            </div>
            <div class="text-xs text-slate-500 mt-3">{{ __('Riders: in Employees, tick “Can do home delivery” on waiters / delivery staff. Online team: give people the “Online Support (orders)” role.') }} · <a href="{{ route('delivery.index') }}" class="text-brand-700 font-semibold">{{ __('Open the delivery board') }}</a></div>
          </div>
        @endif
        @if($title === 'Mobile app')
          <div class="mt-4 text-sm rounded-lg bg-slate-50 border p-3">{{ __('Phones will be told to use') }}: <b>{{ \App\Support\Brand::serverUrl() }}</b> · <a href="{{ route('app.guide') }}" target="_blank" class="text-brand-700 font-semibold">{{ __('Open the app setup guide') }}</a></div>
        @endif
      </div>
    @endforeach
    <button class="btn btn-primary mt-5">{{ __('Save settings') }}</button>
  </form>
</div>
@endsection
