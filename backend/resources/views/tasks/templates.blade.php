@extends('layouts.app')
@section('title', __('Recurring checklists'))
@section('content')
<div class="card overflow-x-auto mb-5"><table class="t">
<tr><th>Checklist</th><th>Location</th><th>Who</th><th>When</th><th>Proof</th><th>Active</th></tr>
@foreach($items as $t)
<tr><td class="font-medium">{{ $t->title }}<div class="text-xs text-slate-400">{{ $t->title_bn }}</div></td><td>{{ $t->location->name }}</td>
<td>{{ $t->assignee?->name ?? ('On-shift team of '.$t->teamLead?->name) }}</td>
<td>{{ $t->recurrence === 'DAILY' ? 'Daily' : 'Weekly ('.$t->weekdays.')' }} · {{ substr($t->due_time,0,5) }}</td><td>{{ $t->proof_required ? '📷' : '' }}</td>
<td><form method="post" action="{{ route('tasks.templates.toggle', $t) }}">@csrf<button class="btn btn-light btn-sm">{{ $t->active ? '✓ on' : 'off' }}</button></form></td></tr>
@endforeach
</table></div>
<form method="post" action="{{ route('tasks.templates.save') }}" class="card p-5 grid sm:grid-cols-3 gap-3" x-data="{rec:'DAILY'}">@csrf
  <div class="sm:col-span-3 font-semibold">+ {{ __('New checklist') }}</div>
  <div><label class="lbl">Title</label><input name="title" class="inp" required></div>
  <div><label class="lbl">Title (বাংলা)</label><input name="title_bn" class="inp"></div>
  <div><label class="lbl">Location</label><select name="location_id" class="inp">@foreach($locations as $id=>$n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select></div>
  <div><label class="lbl">Specific person</label><select name="assignee_id" class="inp"><option value="">— or team below —</option>@foreach($people as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach</select></div>
  <div><label class="lbl">Team of (lead) — goes to whoever is on shift</label><select name="team_lead_id" class="inp"><option value="">—</option>@foreach($people as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach</select></div>
  <div><label class="lbl">Due time</label><input type="time" name="due_time" class="inp" value="09:00" required></div>
  <div><label class="lbl">Repeat</label><select name="recurrence" class="inp" x-model="rec"><option value="DAILY">Daily</option><option value="WEEKLY">Weekly</option></select></div>
  <div x-show="rec==='WEEKLY'" class="sm:col-span-2 flex gap-2 text-sm items-end">@foreach(['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $i=>$d)<label><input type="checkbox" name="weekdays[]" value="{{ $i }}"> {{ $d }}</label>@endforeach</div>
  <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="proof_required" value="1"> Photo proof</label>
  <div class="sm:col-span-3"><button class="btn btn-primary">{{ __('Save') }}</button></div>
</form>
@endsection
