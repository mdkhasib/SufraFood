@extends('layouts.app')
@section('title', __('POS orders'))
@section('content')
@php($U = \App\Support\Ui::class)
<div class="card overflow-x-auto mb-5"><div class="px-4 py-2 border-b font-semibold">Cashier shifts</div><table class="t">
<tr><th>Cashier</th><th>Branch</th><th>Opened</th><th>Closed</th><th>Opening</th><th>Expected cash</th><th>Counted</th><th>Difference</th></tr>
@foreach($shifts as $s)<tr><td>{{ $s->cashier->name }}</td><td>{{ $s->location->name }}</td><td>{{ $s->opened_at->format('d M h:i A') }}</td><td>{{ $s->closed_at?->format('h:i A') ?? 'open' }}</td><td>{{ $U::tk($s->opening_cash) }}</td><td>{{ $s->expected_cash !== null ? $U::tk($s->expected_cash) : '' }}</td><td>{{ $s->counted_cash !== null ? $U::tk($s->counted_cash) : '' }}</td>
<td class="{{ $s->difference && abs($s->difference)>=1 ? 'text-red-600 font-bold' : '' }}">{{ $s->difference !== null ? $U::tk($s->difference, 2) : '' }}</td></tr>@endforeach
</table></div>
<div class="card overflow-x-auto"><table class="t">
<tr><th>No.</th><th>Branch</th><th>Type</th><th>Items</th><th>Total</th><th>Paid by</th><th>Status</th><th></th></tr>
@foreach($orders as $o)
<tr><td>{{ $o->number }}<div class="text-xs text-slate-400">{{ $o->created_at->format('d M h:i A') }} · {{ $o->cashier->name }}</div></td><td>{{ $o->location->name }}</td><td>{{ $o->order_type }}</td>
<td class="text-xs">{{ $o->lines->map(fn($l)=>$l->item->name.' ×'.(float)$l->qty)->join(', ') }}</td><td class="font-semibold">{{ $U::tk($o->total, 2) }}</td>
<td class="text-xs">{{ $o->payments->map(fn($p)=>$p->paymentMethod->name.' '.number_format($p->amount))->join(', ') }}</td><td>{!! $U::badge($o->status) !!} <span class="text-xs">{{ $o->void_reason }}</span></td>
<td>@if($o->status==='PAID')<a href="{{ route('pos.receipt', $o) }}" target="_blank" class="btn btn-light btn-sm">🧾</a>@endif
@if($o->status==='OPEN' && auth()->user()->hasPerm('pos.void'))<form method="post" action="{{ route('pos.void', $o) }}" class="flex gap-1">@csrf<input name="reason" class="inp !py-1 !w-28" placeholder="reason" required><button class="btn btn-red btn-sm">Void</button></form>@endif</td></tr>
@endforeach
</table></div>
<div class="mt-3">{{ $orders->links() }}</div>
@endsection
