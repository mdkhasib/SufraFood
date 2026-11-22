@extends('layouts.app')
@section('title', __('New catering event'))
@section('content')
<form method="post" action="{{ route('cat.store') }}" class="card p-5 max-w-5xl" x-data="{food:[{item_id:'',qty:''}], equip:[{item_id:'',qty:''}], guests:100, pph:500}">@csrf
  <div class="grid sm:grid-cols-3 gap-4">
    <div class="sm:col-span-2"><label class="lbl">Event title</label><input name="title" class="inp" required placeholder="Wedding reception (… family)"></div>
    <div><label class="lbl">Date & time</label><input type="datetime-local" name="event_at" class="inp" required value="{{ now()->addDays(7)->setTime(19,0)->format('Y-m-d\TH:i') }}"></div>
    <div><label class="lbl">Client</label><input name="client_name" class="inp" required></div>
    <div><label class="lbl">Client phone</label><input name="client_phone" class="inp"></div>
    <div><label class="lbl">Venue</label><input name="venue" class="inp" required></div>
    <div><label class="lbl">Venue GPS lat (staff check-in)</label><input name="lat" type="number" step="any" class="inp"></div>
    <div><label class="lbl">Venue GPS lng</label><input name="lng" type="number" step="any" class="inp"></div>
    <div><label class="lbl">Package</label><input name="package_name" class="inp"></div>
    <div><label class="lbl">Guests</label><input name="guests" type="number" class="inp" x-model="guests" required></div>
    <div><label class="lbl">Price per head ৳</label><input name="price_per_head" type="number" class="inp" x-model="pph" required></div>
    <div class="flex items-end"><div class="text-lg">Total <b x-text="'৳'+(guests*pph).toLocaleString()"></b></div></div>
  </div>
  <div class="grid md:grid-cols-2 gap-6 mt-6">
    <div><div class="font-semibold mb-2">🍛 Food from Central Kitchen</div>
      <template x-for="(l,i) in food" :key="i"><div class="flex gap-2 mb-2"><select class="inp" :name="'food['+i+'][item_id]'"><option value="">—</option>@foreach($food as $f)<option value="{{ $f->id }}">{{ $f->name }} ({{ $f->unit }})</option>@endforeach</select><input class="inp !w-24" type="number" step="any" :name="'food['+i+'][qty]'" placeholder="qty"></div></template>
      <button type="button" class="btn btn-light btn-sm" @click="food.push({})">+ food</button></div>
    <div><div class="font-semibold mb-2">🍽 Equipment from Warehouse</div>
      <template x-for="(l,i) in equip" :key="i"><div class="flex gap-2 mb-2"><select class="inp" :name="'equip['+i+'][item_id]'"><option value="">—</option>@foreach($equip as $f)<option value="{{ $f->id }}">{{ $f->name }} ({{ $f->unit }})</option>@endforeach</select><input class="inp !w-24" type="number" :name="'equip['+i+'][qty]'" placeholder="qty"></div></template>
      <button type="button" class="btn btn-light btn-sm" @click="equip.push({})">+ equipment</button></div>
  </div>
  <div class="mt-6"><div class="font-semibold mb-2">👥 Event staff (get tasks + can check in at the venue)</div>
    <div class="grid sm:grid-cols-3 gap-1 text-sm border rounded-lg p-2">@foreach($staff as $s)<label><input type="checkbox" name="staff[]" value="{{ $s->id }}"> {{ $s->name }} <span class="text-xs text-slate-400">{{ $s->position }}</span></label>@endforeach</div></div>
  <div class="mt-4"><label class="lbl">Notes</label><textarea name="notes" class="inp" rows="2"></textarea></div>
  <button class="btn btn-primary mt-4">{{ __('Save as inquiry') }}</button>
</form>
@endsection
