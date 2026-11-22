@extends('layouts.app')
@section('title', __('Fixed (standing) orders'))
@section('content')
@php($days = [6=>'Sat',0=>'Sun',1=>'Mon',2=>'Tue',3=>'Wed',4=>'Thu',5=>'Fri'])
@if($locations->count())<form class="mb-3"><select name="location_id" class="inp !w-60" onchange="this.form.submit()">@foreach($locations as $l)<option value="{{ $l->id }}" @selected($locId==$l->id)>{{ $l->name }}</option>@endforeach</select></form>@endif
<p class="text-sm text-slate-500 mb-3">The order for tomorrow is created automatically every day from this par list and stays editable until the cut-off ({{ \App\Support\Features::setting('orders.cutoff_time') }}). Then it is locked and sent to the Central Kitchen.</p>
@if($drafts->count())
<div class="card p-4 mb-4"><div class="font-semibold mb-2">Drafts waiting for cut-off (edit before {{ \App\Support\Features::setting('orders.cutoff_time') }})</div>
@foreach($drafts as $d)<a href="{{ route('req.show', $d) }}" class="block text-sm py-1">{{ $d->number }} for {{ $d->needed_by->format('D d M') }} · {{ $d->lines->map(fn($l)=>$l->item->name.' '.\App\Support\Ui::q($l->qty_requested))->join(', ') }} → edit</a>@endforeach</div>
@endif
<form method="post" action="{{ route('req.standing.save') }}">@csrf<input type="hidden" name="location_id" value="{{ $locId }}">
<div class="card overflow-x-auto"><table class="t">
<tr><th>Item</th>@foreach($days as $d)<th>{{ $d }}</th>@endforeach</tr>
@foreach($items as $it)
<tr><td class="font-medium whitespace-nowrap">{{ $it->name }} <span class="text-xs text-slate-400">{{ $it->unit }}</span></td>
@foreach($days as $wd=>$d)
  @php($cur = ($rows[$it->id] ?? collect())->firstWhere('weekday', $wd))
  <td><input type="number" step="any" min="0" name="qty[{{ $it->id }}][{{ $wd }}]" value="{{ $cur ? \App\Support\Ui::q($cur->qty) : '' }}" class="inp !w-20 !py-1"></td>
@endforeach</tr>
@endforeach
</table></div>
<div class="mt-3 flex gap-3 items-center"><button class="btn btn-primary">{{ __('Save par list') }}</button><label class="text-sm"><input type="checkbox" name="generate_now" value="1"> also create tomorrow's draft now</label></div>
</form>
@endsection
