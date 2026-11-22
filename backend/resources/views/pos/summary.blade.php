@extends('layouts.app')
@section('title', __('POS day summary'))
@section('content')
@php($U = \App\Support\Ui::class)
<form class="mb-4"><input type="date" name="date" value="{{ $date }}" class="inp !w-48" onchange="this.form.submit()"></form>
<div class="card overflow-x-auto"><table class="t">
<tr><th>Branch</th><th>Orders</th><th>Gross</th><th>Discount</th><th>VAT</th><th>Total</th><th>By payment method</th></tr>
@foreach($rows as $r) @php($s = $r['s'])
<tr><td class="font-semibold">{{ $r['location']->name }}</td><td>{{ $s['orders'] }} <div class="text-xs text-slate-400">@foreach($s['by_type'] as $k=>$v){{ $k }} {{ $v }} @endforeach</div></td>
<td>{{ $U::tk($s['gross']) }}</td><td>{{ $U::tk($s['discount']) }}</td><td>{{ $U::tk($s['vat']) }}</td><td class="font-bold">{{ $U::tk($s['total']) }}</td>
<td class="text-xs">@foreach($s['by_method'] as $k=>$v)<span class="badge bg-slate-100 mr-1">{{ $k }} {{ $U::tk($v) }}</span>@endforeach</td></tr>
@endforeach
<tr class="font-bold bg-orange-50"><td>Total</td><td>{{ $rows->sum(fn($r)=>$r['s']['orders']) }}</td><td>{{ $U::tk($rows->sum(fn($r)=>$r['s']['gross'])) }}</td><td>{{ $U::tk($rows->sum(fn($r)=>$r['s']['discount'])) }}</td><td>{{ $U::tk($rows->sum(fn($r)=>$r['s']['vat'])) }}</td><td>{{ $U::tk($rows->sum(fn($r)=>$r['s']['total'])) }}</td><td></td></tr>
</table></div>
@endsection
