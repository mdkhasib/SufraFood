@extends('layouts.app')
@section('title', __('My attendance'))
@section('content')
@php($U = \App\Support\Ui::class)
<form class="mb-4"><input type="month" name="month" value="{{ $month }}" class="inp !w-48" onchange="this.form.submit()"></form>
<div class="flex flex-wrap gap-2 mb-4">@foreach($cal['summary'] as $k=>$v)<span class="card px-3 py-1.5 text-sm">{!! $U::badge($k) !!} <b>{{ $v }}</b></span>@endforeach</div>
<div class="grid lg:grid-cols-3 gap-4">
<div class="card overflow-x-auto lg:col-span-2"><table class="t">
<tr><th>Date</th><th>Shift</th><th>Status</th><th>In</th><th>Out</th></tr>
@foreach(array_reverse($cal['days']) as $d)
<tr><td>{{ \Carbon\Carbon::parse($d['date'])->format('D d M') }}</td><td class="text-xs">{{ $d['shift'] ?? '—' }}</td><td>{!! $U::badge($d['status']) !!} @if(($d['method'] ?? null) && $d['method']!=='SELF'){!! $U::badge($d['method']) !!}@endif</td><td>{{ $d['in'] ?? '' }}</td><td>{{ $d['out'] ?? '' }}</td></tr>
@endforeach
</table></div>
<div class="card p-4"><div class="font-semibold mb-2">{{ __('Upcoming shifts') }}</div>
@foreach($roster as $x)<div class="text-sm py-1 border-b last:border-0 flex justify-between"><span>{{ $x->date->format('D d M') }}</span><span>{{ $x->is_off ? 'OFF' : $x->shift?->label() }}</span></div>@endforeach
</div>
</div>
@endsection
