@extends('layouts.app')
@section('title', __('KPI'))
@section('content')
@php($color = fn($s) => $s === null ? 'text-slate-400' : ($s >= 90 ? 'text-green-600' : ($s >= 80 ? 'text-amber-600' : 'text-red-600')))
<form class="mb-4 flex gap-2"><input type="month" name="month" value="{{ $month }}" class="inp !w-48" onchange="this.form.submit()">
  @if($locations->count())<select name="location_id" class="inp !w-56" onchange="this.form.submit()">@foreach($locations as $l)<option value="{{ $l->id }}" @selected($locId==$l->id)>{{ $l->name }}</option>@endforeach</select>@endif</form>
@if($mine)
<div class="card p-5 mb-5 grid sm:grid-cols-5 gap-4 items-center">
  <div class="text-center"><div class="text-xs text-slate-500">{{ __('My score') }}</div><div class="text-5xl font-bold {{ $color($mine['score']) }}">{{ $mine['score'] ?? '—' }}</div></div>
  <div><div class="text-xs text-slate-500">Tasks on time</div><div class="text-xl font-bold">{{ $mine['on_time_rate'] ?? '—' }}%</div><div class="text-xs">{{ $mine['tasks_on_time'] }}/{{ $mine['tasks_total'] }}</div></div>
  <div><div class="text-xs text-slate-500">Completion</div><div class="text-xl font-bold">{{ $mine['completion_rate'] ?? '—' }}%</div><div class="text-xs">rework {{ $mine['rework'] }}</div></div>
  <div><div class="text-xs text-slate-500">Attendance</div><div class="text-xl font-bold">{{ $mine['attendance_rate'] ?? '—' }}%</div><div class="text-xs">{{ $mine['present_days'] }}/{{ $mine['rostered_days'] }} days</div></div>
  <div><div class="text-xs text-slate-500">Punctuality</div><div class="text-xl font-bold">{{ $mine['punctuality_rate'] ?? '—' }}%</div><div class="text-xs">{{ $mine['late_days'] }} late</div></div>
</div>
@endif
@if(count($team))
<div class="card overflow-x-auto"><div class="px-4 py-2 font-semibold border-b">{{ __('Team ranking') }} ({{ count($team) }})</div><table class="t">
<tr><th>#</th><th>Employee</th><th>Score</th><th>Tasks on time</th><th>Completion</th><th>Attendance</th><th>Punctuality</th><th>Late</th></tr>
@foreach($team as $i => $row) @php($k = $row['kpi'])
<tr><td>{{ $i+1 }}</td><td><b>{{ $row['user']->name }}</b><div class="text-xs text-slate-400">{{ $row['user']->position }} · {{ $row['user']->location?->code }}</div></td>
<td class="text-lg font-bold {{ $color($k['score']) }}">{{ $k['score'] ?? '—' }}</td><td>{{ $k['on_time_rate'] ?? '—' }}% <span class="text-xs text-slate-400">({{ $k['tasks_on_time'] }}/{{ $k['tasks_total'] }})</span></td>
<td>{{ $k['completion_rate'] ?? '—' }}%</td><td>{{ $k['attendance_rate'] ?? '—' }}%</td><td>{{ $k['punctuality_rate'] ?? '—' }}%</td><td>{{ $k['late_days'] }}</td></tr>
@endforeach
</table></div>
@endif
@endsection
