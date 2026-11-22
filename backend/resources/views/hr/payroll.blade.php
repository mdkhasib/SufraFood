@extends('layouts.app')
@section('title', __('Payroll'))
@section('content')
@php($U = \App\Support\Ui::class)
@if(auth()->user()->hasPerm('payroll.manage'))
<form method="post" action="{{ route('payroll.generate') }}" class="card p-4 mb-4 flex flex-wrap gap-3 items-end">@csrf
  <div><label class="lbl">Month</label><input type="month" name="month" value="{{ now()->format('Y-m') }}" class="inp"></div>
  <button class="btn btn-primary">{{ __('Calculate payroll from attendance') }}</button>
  <p class="text-xs text-slate-500">Uses roster + GPS attendance (present, absent, late, leave), approved overtime, KPI incentive (if activated) and salary-advance instalments.</p>
</form>
@endif
<div class="card overflow-x-auto"><table class="t">
<tr><th>Month</th><th>Employees</th><th>Total net</th><th>Status</th><th>Paid</th><th></th></tr>
@foreach($runs as $run)<tr><td class="font-semibold">{{ \Carbon\Carbon::parse($run->month.'-01')->format('F Y') }}</td><td>{{ $run->payslips_count }}</td><td>{{ $U::tk($run->total_net) }}</td><td>{!! $U::badge($run->status) !!}</td><td>{{ $run->paid_at?->format('d M Y') }}</td>
<td><a href="{{ route('payroll.run', $run) }}" class="btn btn-light btn-sm">{{ __('Open') }}</a></td></tr>@endforeach
</table></div>
@endsection
