@extends('layouts.app')
@section('title', __('Proxy check-in permissions'))
@section('content')
<p class="text-sm text-slate-500 mb-3">Exception only: a staff member can check in a <b>fixed list</b> of up to {{ \App\Support\Features::setting('attendance.proxy_max', 2) }} people from your team. The proxy must be checked in, inside the work area, on their own phone. Every proxy check-in is marked PROXY and you are notified.</p>
<div class="card overflow-x-auto mb-5"><table class="t">
<tr><th>Proxy person</th><th>Can check in</th><th>Valid</th><th>Reason</th><th>Given by</th><th></th></tr>
@forelse($grants as $g)
<tr><td class="font-medium">{{ $g->proxy->name }}</td><td>{{ $g->staff->pluck('name')->join(', ') }}</td>
<td>{{ $g->valid_from?->format('d M') ?? 'now' }} – {{ $g->valid_to?->format('d M') ?? 'until revoked' }}</td><td>{{ $g->reason }}</td><td>{{ $g->manager->name }}</td>
<td><form method="post" action="{{ route('proxy.revoke', $g) }}">@csrf<button class="btn btn-light btn-sm text-red-600">Revoke</button></form></td></tr>
@empty <tr><td colspan="6" class="text-center text-slate-500 py-6">No proxy permissions</td></tr> @endforelse
</table></div>
<form method="post" action="{{ route('proxy.save') }}" class="card p-5 grid sm:grid-cols-2 gap-3">@csrf
  <div class="sm:col-span-2 font-semibold">+ {{ __('Give proxy permission') }}</div>
  <div><label class="lbl">Proxy person</label><select name="proxy_user_id" class="inp">@foreach($team as $p)<option value="{{ $p->id }}">{{ $p->name }} ({{ $p->position }})</option>@endforeach</select></div>
  <div><label class="lbl">Reason</label><input name="reason" class="inp" placeholder="e.g. phone broken"></div>
  <div class="sm:col-span-2"><label class="lbl">Staff they may check in (fixed list)</label>
    <div class="grid sm:grid-cols-3 gap-1 border rounded-lg p-2 max-h-48 overflow-y-auto text-sm">@foreach($team as $p)<label><input type="checkbox" name="staff_ids[]" value="{{ $p->id }}"> {{ $p->name }}</label>@endforeach</div></div>
  <div><label class="lbl">Valid from (optional)</label><input type="date" name="valid_from" class="inp"></div>
  <div><label class="lbl">Valid to (blank = permanent)</label><input type="date" name="valid_to" class="inp"></div>
  <div><button class="btn btn-primary">{{ __('Save') }}</button></div>
</form>
@endsection
