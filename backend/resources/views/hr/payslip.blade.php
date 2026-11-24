@extends('layouts.app')
@section('title', __('Payslip'))
@section('content')
@php($U = \App\Support\Ui::class)
<div class="card p-6 max-w-2xl" id="slip">
  <div class="flex justify-between items-start border-b pb-3 mb-3">
    @php($slipLogo = \App\Support\Brand::logoUrl())
    <div class="flex items-center gap-3">@if($slipLogo)<img src="{{ $slipLogo }}" class="h-12 w-12 object-contain" alt="">@endif<div><div class="text-lg font-bold">{{ \App\Support\Features::setting('company.name') }}</div><div class="text-xs text-slate-500">{{ \App\Support\Features::setting('company.address') }}</div><div class="text-sm text-slate-500">Payslip / বেতন বিবরণী · {{ \Carbon\Carbon::parse($p->run->month.'-01')->format('F Y') }}</div></div></div>
    <button onclick="window.print()" class="btn btn-light btn-sm print:hidden">🖨 Print</button>
  </div>
  <div class="grid grid-cols-2 text-sm gap-1 mb-4">
    <div>Name / নাম: <b>{{ $p->user->name }}</b> {{ $p->user->name_bn }}</div><div>ID: <b>{{ $p->user->employee_code }}</b></div>
    <div>{{ $p->user->position }}</div><div>{{ $p->user->location?->name }}</div>
    <div>Days present: {{ $p->present_days }} / {{ $p->rostered_days }} · Leave {{ $p->leave_days+0 }} · Absent {{ $p->absent_days+0 }}</div><div>Late: {{ $p->late_count }} · OT: {{ $p->ot_hours }} h</div>
  </div>
  <div class="grid grid-cols-2 gap-6 text-sm">
    <table class="w-full"><tr class="border-b"><th class="text-left py-1">Earnings / আয়</th><th class="text-right">৳</th></tr>
      @foreach(['Basic'=>$p->basic,'House rent'=>$p->house_rent,'Medical'=>$p->medical,'Conveyance'=>$p->conveyance,'Overtime'=>$p->ot_amount,'KPI incentive'.($p->kpi_score!==null ? ' (score '.$p->kpi_score.')' : '')=>$p->kpi_incentive,'Home delivery bonus'.($p->delivery_count ? ' ('.$p->delivery_count.' × deliveries)' : '')=>$p->delivery_bonus,'Bonus'=>$p->bonus] as $k=>$v) @if($v>0)<tr><td class="py-0.5">{{ $k }}</td><td class="text-right">{{ number_format($v) }}</td></tr>@endif @endforeach
      <tr class="border-t font-bold"><td>Gross</td><td class="text-right">{{ number_format($p->gross) }}</td></tr></table>
    <table class="w-full"><tr class="border-b"><th class="text-left py-1">Deductions / কর্তন</th><th class="text-right">৳</th></tr>
      @foreach(['Late deduction'=>$p->late_deduction,'Absent deduction'=>$p->absent_deduction,'Advance instalment'=>$p->advance_deduction] as $k=>$v) @if($v>0)<tr><td class="py-0.5">{{ $k }}</td><td class="text-right">{{ number_format($v) }}</td></tr>@endif @endforeach
      <tr class="border-t font-bold"><td>Total</td><td class="text-right">{{ number_format($p->total_deduction) }}</td></tr></table>
  </div>
  <div class="mt-5 rounded-lg bg-orange-50 p-3 flex justify-between items-center"><span class="font-semibold">Net pay / নিট বেতন</span><span class="text-2xl font-bold">{{ $U::tk($p->net) }}</span></div>
  <div class="text-xs text-slate-500 mt-2">Paid via {{ $p->payout_method }} {{ $p->user->payout_account ? '· ****'.substr($p->user->payout_account,-4) : '' }} · {{ $p->paid_at?->format('d M Y') ?? 'not paid yet' }}</div>
</div>
@endsection
