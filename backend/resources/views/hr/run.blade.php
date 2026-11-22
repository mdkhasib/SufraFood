@extends('layouts.app')
@section('title', __('Payroll').' '.$run->month)
@section('content')
@php($U = \App\Support\Ui::class)
@php($me = auth()->user())
<div class="card p-4 mb-4 flex flex-wrap items-center gap-4">
  <div><div class="text-xs text-slate-500">Status</div>{!! $U::badge($run->status) !!}</div>
  <div><div class="text-xs text-slate-500">Employees</div><b>{{ $slips->count() }}</b></div>
  <div><div class="text-xs text-slate-500">Gross</div><b>{{ $U::tk($slips->sum('gross')) }}</b></div>
  <div><div class="text-xs text-slate-500">Deductions</div><b>{{ $U::tk($slips->sum('total_deduction')) }}</b></div>
  <div><div class="text-xs text-slate-500">Net pay</div><b class="text-lg">{{ $U::tk($run->total_net) }}</b></div>
  <div class="text-xs">By method: @foreach($slips->groupBy('payout_method') as $m=>$g) {{ $m }} {{ $U::tk($g->sum('net')) }} · @endforeach</div>
  <div class="ml-auto flex gap-2">
    @if($run->status==='DRAFT' && $me->hasPerm('payroll.manage'))
      <form method="post" action="{{ route('payroll.action', [$run,'recalculate']) }}">@csrf<button class="btn btn-light">↻ Recalculate</button></form>
      <form method="post" action="{{ route('payroll.action', [$run,'submit']) }}">@csrf<button class="btn btn-primary">Submit for approval</button></form>
    @endif
    @if($run->status==='APPROVED' && ($me->hasPerm('acc.manage') || $me->hasPerm('payroll.manage')))
      <form method="post" action="{{ route('payroll.action', [$run,'pay']) }}" onsubmit="return confirm('Pay all salaries and post to accounts?')">@csrf<button class="btn btn-green">💸 Pay & publish payslips</button></form>
    @endif
  </div>
</div>
@if($steps->count())<div class="card p-3 mb-4 text-sm flex flex-wrap gap-3">@foreach($steps as $s)<span>Level {{ $s->level }}: {{ ucwords(str_replace('_',' ',$s->approver_value)) }} {!! $U::badge($s->status) !!} {{ $s->actor?->name }}</span>@endforeach</div>@endif
<div class="card overflow-x-auto"><table class="t text-xs">
<tr><th>Employee</th><th>Days P/A/L</th><th>Late</th><th>Basic</th><th>Allow.</th><th>OT</th><th>KPI</th><th>Deduct</th><th>Gross</th><th>Net</th><th>Method</th><th></th></tr>
@foreach($slips as $p)
<tr><td><b>{{ $p->user->name }}</b><div class="text-slate-400">{{ $p->user->employee_code }} · {{ $p->user->location?->code }}</div></td>
<td>{{ $p->present_days }}/{{ $p->absent_days+0 }}/{{ $p->leave_days+0 }} <span class="text-slate-400">of {{ $p->rostered_days }}</span></td><td>{{ $p->late_count }}</td>
<td>{{ number_format($p->basic) }}</td><td>{{ number_format($p->house_rent+$p->medical+$p->conveyance) }}</td><td>{{ $p->ot_amount ? number_format($p->ot_amount).' ('.$p->ot_hours.'h)' : '' }}</td>
<td>{{ $p->kpi_score !== null ? $p->kpi_score.' → '.number_format($p->kpi_incentive) : '' }}</td><td class="text-red-600">{{ $p->total_deduction ? number_format($p->total_deduction) : '' }}</td>
<td>{{ number_format($p->gross) }}</td><td class="font-bold">{{ number_format($p->net) }}</td><td>{{ $p->payout_method }}</td>
<td><a href="{{ route('payslip', $p) }}" class="text-brand-700">slip</a></td></tr>
@endforeach
</table></div>
@endsection
