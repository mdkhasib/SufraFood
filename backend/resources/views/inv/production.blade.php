@extends('layouts.app')
@section('title', __('Central kitchen production'))
@section('content')
@php($U = \App\Support\Ui::class)
@php($me = auth()->user())
<form class="flex gap-2 mb-4 items-end"><div><label class="lbl">Production date</label><input type="date" name="date" value="{{ $date->toDateString() }}" class="inp" onchange="this.form.submit()"></div>
  <a class="btn btn-light" href="?date={{ $date->copy()->addDay()->toDateString() }}">Tomorrow →</a></form>
<div class="card overflow-x-auto mb-4"><div class="px-4 py-2 border-b font-semibold">📋 Demand for {{ $date->format('D d M') }} (all branches + catering)</div><table class="t">
<tr><th>Item</th><th>Confirmed orders</th><th>Waiting acceptance</th><th>Planned</th><th>Done</th><th>CK stock</th><th>Sources</th></tr>
@forelse($plan['rows'] as $row)
<tr><td class="font-semibold">{{ $row['item']->name }}</td><td>{{ $U::q($row['confirmed']) }} {{ $row['item']->unit }}</td><td class="text-amber-700">{{ $row['waiting'] ? $U::q($row['waiting']) : '' }}</td>
<td>{{ $U::q($row['planned']) }}</td><td class="text-green-700">{{ $U::q($row['done']) }}</td><td>{{ $U::q($row['stock']) }}</td><td class="text-xs">{{ implode(' · ', $row['sources']) }}</td></tr>
@empty <tr><td colspan="7" class="text-center text-slate-500 py-6">No food orders for this date yet</td></tr> @endforelse
</table>
@if($me->hasPerm('production.manage'))
<div class="p-3 border-t flex flex-wrap gap-2 items-end">
  <form method="post" action="{{ route('prod.action','plan') }}" class="flex gap-2 items-end">@csrf<input type="hidden" name="date" value="{{ $date->toDateString() }}">
    <div><label class="lbl">Assign cook</label><select name="assigned_to" class="inp !w-48"><option value="">—</option>@foreach($cooks as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></div>
    <button class="btn btn-primary">Create production orders</button></form>
  <form method="post" action="{{ route('prod.action','raw') }}">@csrf<input type="hidden" name="date" value="{{ $date->toDateString() }}"><button class="btn btn-dark">Request raw material from warehouse</button></form>
</div>
@endif
</div>
<div class="grid lg:grid-cols-2 gap-4">
<div class="card overflow-x-auto"><div class="px-4 py-2 border-b font-semibold">🔥 Production orders</div><table class="t">
<tr><th>No.</th><th>Item</th><th>Qty</th><th>Cook</th><th>Status</th><th></th></tr>
@foreach($orders as $o)
<tr><td>{{ $o->number }}</td><td>{{ $o->item->name }}</td><td>{{ $U::q($o->qty) }} {{ $o->item->unit }}</td><td>{{ $o->assignee?->name }}</td><td>{!! $U::badge($o->status) !!} <div class="text-xs">{{ $o->completed_at?->format('h:i A') }}</div></td>
<td>@if($o->status==='PLANNED')<form method="post" action="{{ route('prod.action','start') }}">@csrf<input type="hidden" name="order_id" value="{{ $o->id }}"><button class="btn btn-light btn-sm">▶ Start</button></form>@endif
@if($o->status!=='DONE')<form method="post" action="{{ route('prod.action','complete') }}" class="flex gap-1 mt-1">@csrf<input type="hidden" name="order_id" value="{{ $o->id }}"><input name="qty" type="number" step="any" value="{{ $U::q($o->qty) }}" class="inp !w-20 !py-1"><button class="btn btn-green btn-sm">✓ Done</button></form>@endif</td></tr>
@endforeach
</table></div>
<div class="card overflow-x-auto"><div class="px-4 py-2 border-b font-semibold">🧺 Raw material needed (unfinished orders)</div><table class="t">
<tr><th>Ingredient</th><th>Need</th><th>CK stock</th><th>To request</th></tr>
@foreach($needs as $n)<tr><td>{{ $n['item']->name }}</td><td>{{ $U::q($n['need']) }} {{ $n['item']->unit }}</td><td>{{ $U::q($n['stock']) }}</td><td class="{{ $n['request']>0 ? 'text-red-600 font-semibold' : 'text-green-700' }}">{{ $n['request']>0 ? $U::q($n['request']) : '✓' }}</td></tr>@endforeach
</table></div>
</div>
@endsection
