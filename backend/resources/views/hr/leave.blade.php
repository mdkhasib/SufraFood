@extends('layouts.app')
@section('title', __('Leave'))
@section('content')
@php($U = \App\Support\Ui::class)
<div class="grid lg:grid-cols-3 gap-4 mb-5">
  <div class="card p-4 lg:col-span-1">
    <div class="font-semibold mb-2">{{ __('My balance') }} ({{ now()->year }})</div>
    @foreach($types as $t)<div class="flex justify-between text-sm py-1 border-b last:border-0"><span>{{ $t->name }}</span><span>{{ $t->days_per_year ? ($t->days_per_year - $t->used).' / '.$t->days_per_year.' left' : 'unpaid' }}</span></div>@endforeach
  </div>
  @if(auth()->user()->hasPerm('leave.request'))
  <form method="post" action="{{ route('leave.request') }}" class="card p-4 lg:col-span-2 grid sm:grid-cols-2 gap-3">@csrf
    <div class="sm:col-span-2 font-semibold">{{ __('Request leave') }}</div>
    <div><label class="lbl">Type</label><select name="leave_type_id" class="inp">@foreach($types as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach</select></div>
    <div><label class="lbl">Reason</label><input name="reason" class="inp"></div>
    <div><label class="lbl">From</label><input type="date" name="from_date" class="inp" required></div>
    <div><label class="lbl">To</label><input type="date" name="to_date" class="inp" required></div>
    <div><button class="btn btn-primary">{{ __('Send') }}</button></div>
  </form>
  @endif
</div>
<div class="card overflow-x-auto mb-5"><div class="px-4 py-2 font-semibold border-b">{{ __('My requests') }}</div><table class="t">
<tr><th>Type</th><th>Dates</th><th>Days</th><th>Reason</th><th>Status</th></tr>
@foreach($mine as $l)<tr><td>{{ $l->leaveType->name }}</td><td>{{ $l->from_date->format('d M') }} – {{ $l->to_date->format('d M Y') }}</td><td>{{ $l->days }}</td><td>{{ $l->reason }}</td><td>{!! $U::badge($l->status) !!}</td></tr>@endforeach
</table></div>
@if($all->count())
<div class="card overflow-x-auto"><div class="px-4 py-2 font-semibold border-b">{{ __('All leave (HR)') }}</div><table class="t">
<tr><th>Employee</th><th>Type</th><th>Dates</th><th>Days</th><th>Status</th></tr>
@foreach($all as $l)<tr><td>{{ $l->user->name }} <span class="text-xs text-slate-400">{{ $l->user->location?->code }}</span></td><td>{{ $l->leaveType->name }}</td><td>{{ $l->from_date->format('d M') }} – {{ $l->to_date->format('d M') }}</td><td>{{ $l->days }}</td><td>{!! $U::badge($l->status) !!}</td></tr>@endforeach
</table></div>
@endif
@endsection
