@extends('layouts.app')
@section('title', __('Roster'))
@section('content')
<form class="flex flex-wrap gap-2 mb-4 items-end">
  @if($locations->count())<div><label class="lbl">Location</label><select name="location_id" class="inp" onchange="this.form.submit()">@foreach($locations as $l)<option value="{{ $l->id }}" @selected($locId==$l->id)>{{ $l->name }}</option>@endforeach</select></div>@endif
  <div><label class="lbl">Week starting</label><input type="date" name="week" value="{{ $start->toDateString() }}" class="inp" onchange="this.form.submit()"></div>
  <a class="btn btn-light" href="?week={{ $start->copy()->subDays(7)->toDateString() }}&location_id={{ $locId }}">← prev</a>
  <a class="btn btn-light" href="?week={{ $start->copy()->addDays(7)->toDateString() }}&location_id={{ $locId }}">next →</a>
</form>
<form method="post" action="{{ route('roster.save') }}">@csrf
<div class="card overflow-x-auto"><table class="t">
<tr><th>Employee</th>@foreach($days as $d)<th class="{{ $d->isToday() ? 'bg-orange-100' : '' }}">{{ $d->format('D d') }}</th>@endforeach</tr>
@foreach($staff as $p)
<tr><td class="whitespace-nowrap"><b>{{ $p->name }}</b><div class="text-xs text-slate-400">{{ $p->position }}</div></td>
@foreach($days as $d)
  @php($cur = $rosters[$p->id][$d->toDateString()] ?? null)
  <td><select name="cell[{{ $p->id }}][{{ $d->toDateString() }}]" class="inp !py-1 !px-1 text-xs {{ $cur?->is_off ? 'bg-slate-100' : '' }}">
    <option value="">—</option><option value="OFF" @selected($cur?->is_off)>OFF</option>
    @foreach($shifts as $s)<option value="{{ $s->id }}" @selected($cur && !$cur->is_off && $cur->shift_template_id==$s->id)>{{ $s->code }} {{ substr($s->start_time,0,5) }}</option>@endforeach
  </select></td>
@endforeach</tr>
@endforeach
</table></div>
<div class="mt-3 flex gap-2"><button class="btn btn-primary">{{ __('Save & publish') }}</button></div>
</form>
<form method="post" action="{{ route('roster.copy') }}" class="mt-2">@csrf<input type="hidden" name="week" value="{{ $start->toDateString() }}"><input type="hidden" name="location_id" value="{{ $locId }}"><button class="btn btn-light btn-sm">Copy this week → next week</button></form>
@endsection
