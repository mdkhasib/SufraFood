@extends('layouts.app')
@section('title', __('Attendance report'))
@section('content')
@php($colors = ['PRESENT'=>'bg-green-500','IN'=>'bg-green-500','OUT'=>'bg-green-500','LATE'=>'bg-amber-400','ABSENT'=>'bg-red-500','NOT_ARRIVED'=>'bg-red-300','LEAVE'=>'bg-purple-400','OFF'=>'bg-slate-200','HOLIDAY'=>'bg-sky-300','NO_SHIFT'=>'bg-white','SCHEDULED'=>'bg-slate-100','NOT_DUE'=>'bg-slate-100'])
<form class="flex flex-wrap gap-2 mb-4 items-end">
  <div><label class="lbl">Month</label><input type="month" name="month" value="{{ $month }}" class="inp" onchange="this.form.submit()"></div>
  @if($locations->count())<div><label class="lbl">Location</label><select name="location_id" class="inp" onchange="this.form.submit()">@foreach($locations as $l)<option value="{{ $l->id }}" @selected($locId==$l->id)>{{ $l->name }}</option>@endforeach</select></div>@endif
  <div class="flex flex-wrap gap-2 text-xs ml-auto">@foreach(['PRESENT'=>'Present','LATE'=>'Late','ABSENT'=>'Absent','LEAVE'=>'Leave','OFF'=>'Off','HOLIDAY'=>'Holiday'] as $k=>$l)<span class="flex items-center gap-1"><span class="inline-block w-3 h-3 rounded {{ $colors[$k] }}"></span>{{ $l }}</span>@endforeach</div>
</form>
<div class="card overflow-x-auto"><table class="t text-xs">
<tr><th>Employee</th>@foreach(($rows->first()['days'] ?? []) as $d)<th class="text-center !px-0.5">{{ \Carbon\Carbon::parse($d['date'])->format('d') }}</th>@endforeach<th>P</th><th>L</th><th>A</th></tr>
@foreach($rows as $row)
<tr><td class="whitespace-nowrap"><b>{{ $row['user']->name }}</b><div class="text-slate-400">{{ $row['user']->position }}</div></td>
@foreach($row['days'] as $d)<td class="!px-0.5 text-center"><span title="{{ $d['date'] }} {{ $d['status'] }} {{ $d['in'] ?? '' }} {{ ($d['method'] ?? '')==='PROXY' ? 'PROXY' : '' }}" class="inline-block w-4 h-4 rounded {{ $colors[$d['status']] ?? 'bg-white' }} {{ ($d['method'] ?? '')==='PROXY' ? 'ring-2 ring-purple-500' : '' }}"></span></td>@endforeach
<td class="font-semibold">{{ ($row['summary']['PRESENT'] ?? 0)+($row['summary']['LATE'] ?? 0)+($row['summary']['IN'] ?? 0)+($row['summary']['OUT'] ?? 0) }}</td>
<td class="text-amber-700">{{ $row['summary']['LATE'] ?? 0 }}</td><td class="text-red-600">{{ $row['summary']['ABSENT'] ?? 0 }}</td></tr>
@endforeach
</table></div>
<p class="text-xs text-slate-400 mt-2">Purple ring = checked in by proxy.</p>
@endsection
