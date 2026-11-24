@extends('layouts.app')
@section('title', __('Delivery report'))
@section('content')
@php($U = \App\Support\Ui::class)
<form class="flex flex-wrap items-end gap-2 mb-4">
  <div><label class="lbl">{{ __('Month') }}</label><input type="month" name="month" value="{{ $month }}" class="inp"></div>
  @if($branches->count() > 1)<div><label class="lbl">{{ __('Branch') }}</label><select name="location" class="inp"><option value="">{{ __('All branches') }}</option>@foreach($branches as $b)<option value="{{ $b->id }}" @selected($loc == $b->id)>{{ $b->name }}</option>@endforeach</select></div>@endif
  <button class="btn btn-light">{{ __('Show') }}</button>
  <a href="{{ route('delivery.index') }}" class="btn btn-light ml-auto">🛵 {{ __('Delivery board') }}</a>
</form>

<div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3 mb-5">
  @foreach($branches as $b)
    @php($s = $byBranch[$b->id] ?? null)
    @continue($loc && $loc != $b->id)
    <div class="card p-4">
      <div class="font-semibold">{{ $b->name }}</div>
      <div class="text-xs text-slate-500">{{ __('Rider rate') }}: {{ $U::tk(\App\Support\Delivery::rate($b)) }} / {{ __('delivery') }}</div>
      <div class="flex gap-4 mt-2 text-sm"><div><div class="text-2xl font-bold">{{ $s->n ?? 0 }}</div>{{ __('deliveries') }}</div><div><div class="text-2xl font-bold">{{ $U::tk($s->sales ?? 0) }}</div>{{ __('sales') }}</div><div><div class="text-2xl font-bold text-brand-700">{{ $U::tk($s->fees ?? 0) }}</div>{{ __('rider bonus') }}</div></div>
    </div>
  @endforeach
</div>

<div class="card overflow-x-auto">
  <div class="px-4 py-2 border-b font-semibold">{{ __('Riders') }} · {{ \Illuminate\Support\Carbon::parse($month.'-01')->format('F Y') }} <span class="text-xs text-slate-500 font-normal">· {{ (int) \App\Support\Features::setting('delivery.bonus_in_payroll', 1) ? __('the bonus is added to their salary in payroll') : __('bonus is not added to payroll (Company settings)') }}</span></div>
  <table class="t">
    <tr><th>{{ __('Rider') }}</th><th>{{ __('Branch') }}</th><th class="text-right">{{ __('Deliveries') }}</th><th class="text-right">{{ __('Avg time') }}</th><th class="text-right">{{ __('Cash collected') }}</th><th class="text-right">{{ __('Bonus') }}</th></tr>
    @forelse($rows as $r)
      <tr><td><b>{{ $r['rider']?->name }}</b> <span class="text-xs text-slate-400">{{ $r['rider']?->employee_code }} · {{ $r['rider']?->position }}</span></td><td>{{ $r['rider']?->location?->name }}</td>
        <td class="text-right font-semibold">{{ $r['count'] }}</td><td class="text-right">{{ $r['avg_min'] }} min</td><td class="text-right">{{ $U::tk($r['cash']) }}</td><td class="text-right font-bold text-brand-700">{{ $U::tk($r['bonus']) }}</td></tr>
    @empty
      <tr><td colspan="6" class="text-center text-slate-400 py-8">{{ __('No deliveries this month') }}</td></tr>
    @endforelse
    @if($rows->count())<tr class="font-bold bg-slate-50"><td colspan="2">{{ __('Total') }}</td><td class="text-right">{{ $rows->sum('count') }}</td><td></td><td class="text-right">{{ $U::tk($rows->sum('cash')) }}</td><td class="text-right">{{ $U::tk($rows->sum('bonus')) }}</td></tr>@endif
  </table>
</div>
@endsection
