@extends('layouts.app')
@section('title', $pa->number)
@section('content')
@php($U = \App\Support\Ui::class)
@php($mine = $pa->buyer_id === auth()->id() || auth()->user()->is_super_admin)
<div class="card p-4 mb-4 flex flex-wrap gap-6 items-center">
  <div><div class="text-xs text-slate-500">Purchase</div><div class="text-xl font-bold">{{ $pa->number }} {!! $U::badge($pa->status) !!}</div></div>
  <div><div class="text-xs text-slate-500">Buyer</div><b>{{ $pa->buyer->name }}</b></div>
  <div><div class="text-xs text-slate-500">Deadline</div><b class="{{ $pa->deadline_at->isPast() && in_array($pa->status,['ASSIGNED','ACCEPTED']) ? 'text-red-600' : '' }}">{{ $pa->deadline_at->format('d M h:i A') }}</b> <span class="text-xs">({{ $pa->deadline_at->diffForHumans() }})</span></div>
  <div><div class="text-xs text-slate-500">Funding</div><b>{{ \App\Services\PurchaseService::FUNDING[$pa->funding][0] }}</b> {{ $pa->paymentMethod?->name }}</div>
  <div><div class="text-xs text-slate-500">Deliver to</div><b>{{ $pa->requisition->fromLocation->name }}</b> · <a class="text-brand-700" href="{{ route('req.show', $pa->requisition) }}">{{ $pa->requisition->number }}</a></div>
</div>
@if($mine && $pa->status==='ASSIGNED')<form method="post" action="{{ route('pur.assignment.action', [$pa,'accept']) }}" class="mb-4">@csrf<button class="btn btn-primary">✓ Accept this purchase</button></form>@endif
@if($mine && in_array($pa->status,['ASSIGNED','ACCEPTED']))
<form method="post" enctype="multipart/form-data" action="{{ route('pur.assignment.action', [$pa,'receipt']) }}" class="card p-4 mb-4">@csrf
  <div class="font-semibold mb-2">🧾 Submit receipt</div>
  <table class="t mb-3"><tr><th>Item</th><th>Requested</th><th>Bought qty</th><th>Unit price ৳ (last {{ '' }})</th></tr>
  @foreach($pa->requisition->lines as $l)<tr><td>{{ $l->item?->name }}</td><td>{{ $U::q($l->qty_requested) }} {{ $l->item?->unit }}</td>
    <td><input type="number" step="any" name="lines[{{ $l->id }}][qty]" value="{{ $U::q($l->qty_requested) }}" class="inp !w-28"></td>
    <td><input type="number" step="any" name="lines[{{ $l->id }}][unit_price]" value="{{ $l->est_price }}" class="inp !w-28"> <span class="text-xs text-slate-400">last {{ $U::tk($l->item?->last_price) }}</span></td></tr>@endforeach</table>
  <div class="grid sm:grid-cols-3 gap-3">
    <div><label class="lbl">Vendor</label><select name="vendor_id" class="inp"><option value="">—</option>@foreach($vendors as $v)<option value="{{ $v->id }}" @selected($pa->vendor_id==$v->id)>{{ $v->name }}</option>@endforeach</select></div>
    <div><label class="lbl">Receipt photo *</label><input type="file" name="photo" accept="image/*" capture="environment" class="text-sm" required></div>
    <div><label class="lbl">Note</label><input name="note" class="inp"></div>
  </div>
  <button class="btn btn-primary mt-3">Submit receipt</button>
</form>
@endif
@if($mine && $pa->status==='BOUGHT')<form method="post" action="{{ route('pur.assignment.action', [$pa,'delivered']) }}" class="mb-4">@csrf<button class="btn btn-green">🚚 Mark delivered to {{ $pa->requisition->fromLocation->name }}</button></form>@endif
@if($pa->receipt_photo)
<div class="card p-4"><div class="font-semibold mb-2">Receipt · {{ $U::tk($pa->actual_amount) }} @if($pa->price_flag)<span class="badge bg-red-100 text-red-700">price above benchmark</span>@endif</div>
  <img src="{{ asset('storage/'.$pa->receipt_photo) }}" class="max-h-80 rounded border"><div class="text-sm mt-2">{{ $pa->receipt_note }} · {{ $pa->vendor?->name }}</div></div>
@endif
@endsection
