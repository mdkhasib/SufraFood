@extends('layouts.app')
@section('title', __('My payslips'))
@section('content')
<div class="card overflow-x-auto"><table class="t">
<tr><th>Month</th><th>Gross</th><th>Deductions</th><th>Net</th><th>Paid</th><th></th></tr>
@forelse($slips as $p)<tr><td>{{ \Carbon\Carbon::parse($p->run->month.'-01')->format('F Y') }}</td><td>{{ number_format($p->gross) }}</td><td>{{ number_format($p->total_deduction) }}</td><td class="font-bold">{{ \App\Support\Ui::tk($p->net) }}</td><td>{{ $p->paid_at?->format('d M Y') }}</td><td><a href="{{ route('payslip', $p) }}" class="btn btn-light btn-sm">View</a></td></tr>
@empty <tr><td colspan="6" class="text-center text-slate-500 py-6">No payslips yet</td></tr> @endforelse
</table></div>
@endsection
