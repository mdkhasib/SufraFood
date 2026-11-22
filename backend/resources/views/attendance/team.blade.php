@extends('layouts.app')
@section('title', __('Team attendance'))
@section('content')
@php($U = \App\Support\Ui::class)
<form class="flex flex-wrap gap-2 mb-4 items-end">
  <div><label class="lbl">Date</label><input type="date" name="date" value="{{ $date }}" class="inp" onchange="this.form.submit()"></div>
  @if($locations->count())<div><label class="lbl">Location</label><select name="location_id" class="inp" onchange="this.form.submit()"><option value="">All</option>@foreach($locations as $l)<option value="{{ $l->id }}" @selected(request('location_id')==$l->id)>{{ $l->name }}</option>@endforeach</select></div>@endif
  <div class="flex flex-wrap gap-2 ml-auto text-sm">
    @foreach(['IN'=>'In','LATE'=>'Late','OUT'=>'Checked out','NOT_ARRIVED'=>'Not arrived','NOT_DUE'=>'Not due yet','LEAVE'=>'Leave','OFF'=>'Off','PRESENT'=>'Present','ABSENT'=>'Absent'] as $k=>$l)
      @if(($board['counts'][$k] ?? 0) > 0)<span class="card px-3 py-1.5">{!! $U::badge($k) !!} <b>{{ $board['counts'][$k] }}</b></span>@endif
    @endforeach
  </div>
</form>
<div class="card overflow-x-auto"><table class="t">
<tr><th>Employee</th><th>Shift</th><th>Status</th><th>In</th><th>Out</th><th>How</th><th>Needs action</th></tr>
@foreach($board['rows'] as $row)
<tr class="{{ $row['status']==='NOT_ARRIVED' ? 'bg-red-50/60' : '' }}">
  <td><div class="font-medium">{{ $row['user']->name }}</div><div class="text-xs text-slate-400">{{ $row['user']->employee_code }} · {{ $row['user']->position }} · {{ $row['user']->location?->code }}</div></td>
  <td class="text-xs">{{ $row['shift'] ?? '—' }}</td>
  <td>{!! $U::badge($row['status']) !!} @if($row['late_minutes'])<span class="text-xs text-amber-700">{{ $row['late_minutes'] }} min</span>@endif</td>
  <td>{{ $row['check_in'] }}</td><td>{{ $row['check_out'] }}</td>
  <td>@if($row['method'] && $row['method']!=='SELF'){!! $U::badge($row['method']) !!} <span class="text-xs">{{ $row['by'] }}</span>@elseif($row['method']) <span class="text-xs text-slate-400">self · GPS</span>@endif</td>
  <td class="whitespace-nowrap">
    @if($row['remote_status']==='PENDING')
      <form method="post" action="{{ route('attendance.decide', [$row['attendance_id'],'remote']) }}" class="inline">@csrf <span class="text-xs">Remote:</span>
        <button name="decision" value="approve" class="btn btn-green btn-sm">✓</button><button name="decision" value="reject" class="btn btn-red btn-sm">✕</button></form>
    @endif
    @if($row['ot_status']==='PENDING')
      <form method="post" action="{{ route('attendance.decide', [$row['attendance_id'],'ot']) }}" class="inline">@csrf <span class="text-xs">OT {{ round($row['ot_minutes']/60,1) }}h:</span>
        <button name="decision" value="approve" class="btn btn-green btn-sm">✓</button><button name="decision" value="reject" class="btn btn-red btn-sm">✕</button></form>
    @endif
  </td>
</tr>
@endforeach
</table></div>
@endsection
