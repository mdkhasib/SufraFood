@extends('layouts.app')
@section('title', __('Home delivery'))
@section('content')
@php($U = \App\Support\Ui::class)
@php($me = auth()->user())
@php($steps = ['COOKING' => __('In kitchen'), 'READY' => __('Ready'), 'PICKED_UP' => __('On the way'), 'DELIVERED' => __('Delivered')])
@php($canPrint = $me->hasAnyPerm(['pos.sell', 'pos.void']))
<div class="flex flex-wrap items-center gap-2 mb-4">
  <div class="flex rounded-lg border overflow-hidden text-sm">
    @foreach(['active' => 'Active', 'done' => 'Done (2 days)', 'mine' => 'Taken by me'] as $k => $l)<a href="?view={{ $k }}&location={{ $loc }}" class="px-3 py-1.5 {{ $view === $k ? 'bg-slate-800 text-white' : 'bg-white' }}">{{ __($l) }}</a>@endforeach
  </div>
  @if($branches->count() > 1)
    <form class="flex items-center gap-1"><input type="hidden" name="view" value="{{ $view }}"><select name="location" class="inp !py-1 !w-44" onchange="this.form.submit()"><option value="">{{ __('All branches') }}</option>@foreach($branches as $b)<option value="{{ $b->id }}" @selected($loc == $b->id)>{{ $b->name }}</option>@endforeach</select></form>
  @endif
  <div class="flex gap-2 text-xs ml-1">
    <span class="badge bg-amber-100 text-amber-800">🍳 {{ $counts['COOKING'] ?? 0 }} {{ __('in kitchen') }}</span>
    <span class="badge bg-blue-100 text-blue-800">✅ {{ $counts['READY'] ?? 0 }} {{ __('ready') }}</span>
    <span class="badge bg-indigo-100 text-indigo-800">🛵 {{ $counts['PICKED_UP'] ?? 0 }} {{ __('on the way') }}</span>
    <span class="badge bg-green-100 text-green-800">✓ {{ $counts['DELIVERED'] ?? 0 }} {{ __('delivered today') }}</span>
    @if($cashWithRiders > 0)<span class="badge bg-red-100 text-red-700">💵 {{ $U::tk($cashWithRiders) }} {{ __('cash with riders') }}</span>@endif
  </div>
  <div class="ml-auto flex gap-2">
    @if($me->hasAnyPerm(['delivery.manage', 'payroll.manage', 'delivery.order']))<a href="{{ route('delivery.report') }}" class="btn btn-light">📊 {{ __('Delivery report') }}</a>@endif
    <a href="{{ route('delivery.create') }}" class="btn btn-primary">+ {{ __('New delivery order') }}</a>
  </div>
</div>

<div class="grid md:grid-cols-2 xl:grid-cols-3 gap-3">
@forelse($orders as $o)
  @php($manage = \App\Support\Delivery::canAssign($me, $o->location_id))
  @php($cashOk = \App\Support\Delivery::canReceiveCash($me, $o->location_id))
  @php($idx = array_search($o->delivery_status, array_keys($steps)))
  <div class="card p-4 {{ $o->delivery_status === 'READY' && !$o->rider_id ? 'border-2 border-red-400' : ($o->delivery_status === 'DELIVERED' && $o->status === 'OPEN' ? 'border-2 border-amber-400' : '') }}">
    <div class="flex items-start gap-2">
      <div>
        <div class="font-bold">{{ $o->number }} <span class="badge {{ $o->source === 'ONLINE' ? 'bg-purple-100 text-purple-800' : 'bg-slate-100' }}">{{ $o->source === 'ONLINE' ? __('Online') : __('Counter') }}</span></div>
        <div class="text-xs text-slate-500">{{ $o->location->name }} · {{ $o->created_at->format('h:i A') }} ({{ $o->created_at->diffForHumans(null, true) }}) · {{ __('by') }} {{ $o->cashier?->name }}</div>
      </div>
      <div class="ml-auto">{!! $o->delivery_status === 'CANCELLED' ? $U::badge('CANCELLED') : $U::badge($o->delivery_status) !!}</div>
    </div>
    @if($o->delivery_status !== 'CANCELLED')
    <div class="flex items-center gap-1 my-3">
      @foreach($steps as $k => $l)
        <div class="flex-1"><div class="h-1.5 rounded-full {{ $idx !== false && $loop->index <= $idx ? 'bg-brand-600' : 'bg-slate-200' }}"></div><div class="text-[10px] mt-0.5 {{ $idx !== false && $loop->index == $idx ? 'font-bold text-brand-700' : 'text-slate-400' }}">{{ $l }}</div></div>
      @endforeach
    </div>
    @endif
    <div class="rounded-lg bg-slate-50 border p-2.5 text-sm space-y-0.5">
      <div class="font-semibold">👤 {{ $o->customer_name ?: __('Customer') }} · <a href="tel:{{ $o->customer_phone }}" class="text-brand-700">{{ $o->customer_phone }}</a></div>
      <div>📍 {{ $o->delivery_address }} @if($o->delivery_area)<b>· {{ $o->delivery_area }}</b>@endif @if($o->mapsUrl())<a href="{{ $o->mapsUrl() }}" target="_blank" class="text-xs text-brand-700 ml-1">{{ __('map') }} ↗</a>@endif</div>
      @if($o->delivery_note)<div class="text-amber-800">📝 {{ $o->delivery_note }}</div>@endif
    </div>
    <div class="text-sm mt-2">{{ $o->lines->map(fn ($l) => rtrim(rtrim(number_format($l->qty, 2), '0'), '.').'× '.$l->item->name)->implode(', ') }}</div>
    <div class="flex items-center gap-2 mt-2 text-sm">
      <b class="text-lg">{{ $U::tk($o->total, 2) }}</b>
      @if($o->status === 'PAID')<span class="badge bg-green-100 text-green-800">{{ __('Paid') }}</span>
      @elseif($o->status === 'VOID')<span class="badge bg-red-100 text-red-700">{{ __('Void') }}</span>
      @else<span class="badge bg-amber-100 text-amber-800">{{ __('Cash on delivery') }}</span>@endif
      @if($canPrint)<a href="{{ route('pos.receipt', $o) }}" target="_blank" class="ml-auto text-xs text-slate-500">🧾 {{ __('bill') }}</a>@endif
    </div>

    <div class="mt-3 border-t pt-3 text-sm">
      @if($o->rider)
        <div>🛵 <b>{{ $o->rider->name }}</b> <span class="text-xs text-slate-500">@if($o->rider_assigned_at){{ __('given') }} {{ $o->rider_assigned_at->format('h:i A') }}@endif @if($o->picked_up_at) · {{ __('picked up') }} {{ $o->picked_up_at->format('h:i A') }}@endif @if($o->delivered_at) · {{ __('delivered') }} {{ $o->delivered_at->format('h:i A') }} ({{ (int) $o->rider_assigned_at?->diffInMinutes($o->delivered_at) }} min)@endif</span></div>
      @elseif(in_array($o->delivery_status, ['COOKING', 'READY']))
        <div class="text-red-600 font-semibold">{{ __('No rider yet') }}</div>
      @endif
      @if($manage && in_array($o->delivery_status, ['COOKING', 'READY']))
        <form method="post" action="{{ route('delivery.action', [$o, 'assign']) }}" class="flex gap-1 mt-2">@csrf
          <select name="rider_id" class="inp !py-1" required><option value="">{{ $o->rider ? __('Change rider…') : __('Choose a rider…') }}</option>
            @foreach($riders[$o->location_id] ?? [] as $rd)<option value="{{ $rd->id }}" @selected($o->rider_id === $rd->id)>{{ $rd->name }} · {{ $rd->position }}{{ ($busy[$rd->id] ?? 0) ? ' · '.$busy[$rd->id].' '.__('on hand') : ' · '.__('free') }}</option>@endforeach
          </select>
          <button class="btn btn-primary btn-sm whitespace-nowrap">🛵 {{ $o->rider ? __('Change') : __('Give') }}</button>
        </form>
        @if(($riders[$o->location_id] ?? collect())->isEmpty())<div class="text-xs text-red-600 mt-1">{{ __('No riders at this branch. Tick “Can do home delivery” on staff in Employees.') }}</div>@endif
      @endif
      @if($o->delivery_status === 'DELIVERED' && $o->status === 'OPEN')
        <div class="mt-2 rounded-lg bg-amber-50 border border-amber-200 p-2 flex items-center gap-2">
          <div class="flex-1">💵 {{ $o->rider?->name }} {{ __('has') }} <b>{{ $U::tk($o->cash_collected ?? $o->total, 2) }}</b> {{ __('cash') }}</div>
          @if($cashOk)<form method="post" action="{{ route('delivery.action', [$o, 'cash']) }}" onsubmit="return confirm('{{ __('Received the cash from the rider?') }}')">@csrf<button class="btn btn-green btn-sm">✓ {{ __('Cash received') }}</button></form>@endif
        </div>
      @endif
      @if(in_array($o->delivery_status, ['COOKING', 'READY', 'PICKED_UP']) && $o->status === 'OPEN' && ($manage || $o->cashier_id === $me->id))
        <details class="mt-2 text-xs"><summary class="cursor-pointer text-slate-400">{{ __('Cancel order') }}</summary>
          <form method="post" action="{{ route('delivery.action', [$o, 'cancel']) }}" class="flex gap-1 mt-1">@csrf<input name="reason" class="inp !py-1" placeholder="{{ __('reason') }}" required><button class="btn btn-light btn-sm text-red-600">{{ __('Cancel') }}</button></form></details>
      @endif
    </div>
  </div>
@empty
  <div class="card p-10 text-center text-slate-400 md:col-span-2 xl:col-span-3">🛵 {{ __('No home delivery orders here') }}</div>
@endforelse
</div>
@push('scripts')
<script>
  // keep the board fresh while nobody is typing or choosing
  setInterval(() => { const a = document.activeElement; if (!(a && ['SELECT', 'INPUT', 'TEXTAREA'].includes(a.tagName)) && !document.querySelector('details[open]')) location.reload(); }, 30000);
</script>
@endpush
@endsection
