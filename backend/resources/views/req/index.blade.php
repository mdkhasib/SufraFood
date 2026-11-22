@extends('layouts.app')
@section('title', __('Requisitions'))
@section('content')
@php($U = \App\Support\Ui::class)
<div class="flex flex-wrap items-center gap-2 mb-4">
  <div class="flex rounded-lg border overflow-hidden text-sm">
    @foreach(['all'=>'All','open'=>'Open','mine'=>'Raised by us','incoming'=>'Incoming to us'] as $k=>$l)<a href="?box={{ $k }}" class="px-3 py-1.5 {{ $box===$k ? 'bg-slate-800 text-white' : 'bg-white' }}">{{ __($l) }}</a>@endforeach
  </div>
  <form><input type="hidden" name="box" value="{{ $box }}"><select name="type" class="inp !w-44" onchange="this.form.submit()"><option value="">All types</option>@foreach(config('groupops.requisition_types') as $k=>$t)<option value="{{ $k }}" @selected(request('type')===$k)>{{ $t[0] }}</option>@endforeach</select></form>
  @if($canCreate)<a href="{{ route('req.create') }}" class="btn btn-primary ml-auto">+ {{ __('New request') }}</a>@endif
</div>
<div class="card overflow-x-auto"><table class="t">
<tr><th>No.</th><th>Type</th><th>From → To</th><th>Items</th><th>Needed by</th><th>Amount</th><th>Status</th></tr>
@forelse($items as $r)
<tr class="cursor-pointer" onclick="location='{{ route('req.show', $r) }}'">
  <td class="font-semibold text-brand-700 whitespace-nowrap">{{ $r->number }}@if($r->priority==='URGENT') {!! $U::badge('URGENT') !!}@endif<div class="text-[11px] text-slate-400">{{ strtolower($r->source) }}</div></td>
  <td>{{ $r->typeLabel() }}</td><td class="whitespace-nowrap">{{ $r->fromLocation->name }} → {{ $r->toLocation->name }}</td>
  <td class="text-xs max-w-xs">{{ $r->lines->map(fn($l)=>($l->item?->displayName() ?? $l->description).' '.$U::q($l->qty_requested).' '.$l->item?->unit)->join(', ') }}</td>
  <td class="whitespace-nowrap {{ $r->needed_by?->isPast() && !in_array($r->status,['CLOSED','CANCELLED','REJECTED']) ? 'text-red-600' : '' }}">{{ $r->needed_by?->format('d M h:i A') }}</td>
  <td>{{ $U::tk($r->estimated_amount) }}</td><td>{!! $U::badge($r->status) !!}</td>
</tr>
@empty <tr><td colspan="7" class="text-center text-slate-500 py-8">No requests</td></tr> @endforelse
</table></div>
<div class="mt-3">{{ $items->links() }}</div>
@endsection
