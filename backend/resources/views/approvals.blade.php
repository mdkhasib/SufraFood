@extends('layouts.app')
@section('title', __('Approvals'))
@section('content')
@php($U = \App\Support\Ui::class)
@php($A = \App\Services\ApprovalService::class)
<div class="card overflow-hidden mb-6">
  <div class="px-4 py-3 font-semibold border-b">{{ __('Waiting for you') }} ({{ $inbox->count() }})</div>
  @forelse($inbox as $s)
    @php($m = $s->approvable)
    <div class="p-4 border-b last:border-0 flex flex-col md:flex-row md:items-center gap-3" x-data="{rej:false}">
      <div class="flex-1">
        <div class="font-semibold">{{ $A::titleOf($m) }} @if($s->due_at && $s->due_at->isPast()) {!! $U::badge('OVERDUE') !!} @endif</div>
        <div class="text-xs text-slate-500">Level {{ $s->level }} · {{ $A::typeOf($m) }} · since {{ $s->updated_at->diffForHumans() }}
          @if($A::amountOf($m) > 0) · <b>{{ $U::tk($A::amountOf($m)) }}</b>@endif
          @if($m instanceof \App\Models\Requisition) · {{ $m->lines->map(fn($l)=>($l->item?->name ?? $l->description).' '.$U::q($l->qty_requested).' '.$l->item?->unit)->join(', ') }} · <a class="text-brand-700" href="{{ route('req.show', $m) }}">open</a>@endif
          @if(isset($m->reason) && $m->reason) · “{{ $m->reason }}” @endif
          @if(isset($m->description) && $m->description) · {{ $m->description }} @endif
          @if($m instanceof \App\Models\PayrollRun) · <a class="text-brand-700" href="{{ route('payroll.run', $m) }}">view payslips</a>@endif
        </div>
      </div>
      <form method="post" action="{{ route('approvals.act', $s) }}" class="flex flex-wrap gap-2 items-center">@csrf
        <input name="comment" class="inp !w-48" placeholder="{{ __('Comment / reason') }}">
        <button name="decision" value="approve" class="btn btn-green">✓ {{ __('Approve') }}</button>
        <button name="decision" value="reject" class="btn btn-red">✕ {{ __('Reject') }}</button>
      </form>
    </div>
  @empty <div class="p-6 text-center text-slate-500">{{ __('Nothing waiting') }} 🎉</div> @endforelse
</div>
<div class="card overflow-hidden">
  <div class="px-4 py-3 font-semibold border-b">{{ __('Recently decided by me') }}</div>
  <table class="t"><tr><th>When</th><th>Item</th><th>Decision</th><th>Comment</th></tr>
  @foreach($mine as $s) @continue(!$s->approvable)
    <tr><td>{{ $s->acted_at?->format('d M h:i A') }}</td><td>{{ $A::titleOf($s->approvable) }}</td><td>{!! $U::badge($s->status) !!}</td><td>{{ $s->comment }}</td></tr>
  @endforeach</table>
</div>
@endsection
