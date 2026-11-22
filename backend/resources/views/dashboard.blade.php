@extends('layouts.app')
@section('title', __('Dashboard'))
@section('content')
@php($U = \App\Support\Ui::class)
<div class="mb-5">
  <h1 class="text-xl font-bold">{{ __('Good day') }}, {{ $u->displayName() }} 👋</h1>
  <p class="text-sm text-slate-500">{{ now()->format('l, d F Y') }} · {{ $u->is_super_admin ? 'Super Admin' : $u->role?->name }} · {{ $u->location?->name }}</p>
</div>

@if($mgmt)
<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
  @isset($mgmt['attendance'])
  <a href="{{ $u->hasPerm('attendance.team_view') ? route('attendance.team') : '#' }}" class="card p-4">
    <div class="text-xs text-slate-500">{{ __('Attendance today') }}</div>
    <div class="text-2xl font-bold">{{ $mgmt['attendance']['in'] }}<span class="text-sm text-slate-400">/{{ $mgmt['attendance']['rostered'] }}</span></div>
    <div class="text-xs mt-1"><span class="text-amber-700">{{ $mgmt['attendance']['late'] }} late</span> · <span class="text-purple-700">{{ $mgmt['attendance']['proxy'] }} proxy</span> · {{ $mgmt['attendance']['leave'] }} leave</div>
  </a>
  @endisset
  <a href="{{ route('approvals') }}" class="card p-4">
    <div class="text-xs text-slate-500">{{ __('Pending approvals') }}</div>
    <div class="text-2xl font-bold {{ $mgmt['approvals'] ? 'text-brand-600' : '' }}">{{ $mgmt['approvals'] }}</div>
    <div class="text-xs mt-1 text-slate-500">{{ __('waiting for you') }}</div>
  </a>
  @isset($mgmt['requests'])
  <a href="{{ route('req.index') }}" class="card p-4">
    <div class="text-xs text-slate-500">{{ __('Open requests') }}</div>
    <div class="text-2xl font-bold">{{ $mgmt['requests']['open'] }}</div>
    <div class="text-xs mt-1 {{ $mgmt['requests']['delayed'] ? 'text-red-600' : 'text-slate-500' }}">{{ $mgmt['requests']['delayed'] }} delayed · {{ $mgmt['low_stock'] }} low-stock items</div>
  </a>
  @endisset
  @isset($mgmt['tasks'])
  <div class="card p-4">
    <div class="text-xs text-slate-500">{{ __('Overdue tasks') }}</div>
    <div class="text-2xl font-bold {{ $mgmt['tasks']['overdue'] ? 'text-red-600' : '' }}">{{ $mgmt['tasks']['overdue'] }}</div>
    <div class="text-xs mt-1 text-slate-500">{{ $mgmt['tasks']['done_today'] }} done today @foreach(array_slice($mgmt['tasks']['by_location'],0,2,true) as $l=>$c) · {{ $l }} {{ $c }}@endforeach</div>
  </div>
  @endisset
  @isset($mgmt['sales'])
  <a href="{{ route('pos.summary') }}" class="card p-4">
    <div class="text-xs text-slate-500">{{ __('Sales today') }}</div>
    <div class="text-2xl font-bold">{{ $U::tk($mgmt['sales']['today']) }}</div>
    <div class="text-xs mt-1 text-slate-500">{{ $mgmt['sales']['orders_today'] }} orders · yesterday {{ $U::tk($mgmt['sales']['yesterday']) }}</div>
  </a>
  @endisset
  @isset($mgmt['purchase'])
  <a href="{{ route('pur.index') }}" class="card p-4">
    <div class="text-xs text-slate-500">{{ __('Purchase spend') }}</div>
    <div class="text-2xl font-bold">{{ $U::tk($mgmt['purchase']['spent_today']) }}</div>
    <div class="text-xs mt-1 text-slate-500">month {{ $U::tk($mgmt['purchase']['spent_month']) }} · {{ $mgmt['purchase']['active'] }} active</div>
  </a>
  @endisset
  @isset($mgmt['money'])
  <a href="{{ route('acc.index') }}" class="card p-4">
    <div class="text-xs text-slate-500">{{ __('Cash · Bank · MFS') }}</div>
    <div class="text-lg font-bold">{{ $U::tk($mgmt['money']['CASH'] + $mgmt['money']['DRAWER'] + $mgmt['money']['PETTY']) }}</div>
    <div class="text-xs mt-1 text-slate-500">Bank {{ $U::tk($mgmt['money']['BANK']) }} · MFS {{ $U::tk($mgmt['money']['MFS']) }}</div>
  </a>
  <a href="{{ route('pur.bills') }}" class="card p-4">
    <div class="text-xs text-slate-500">{{ __('Payables due (7 days)') }}</div>
    <div class="text-2xl font-bold">{{ $U::tk($mgmt['payables_week']) }}</div>
  </a>
  @endisset
  @isset($mgmt['catering'])
  <a href="{{ route('cat.index') }}" class="card p-4">
    <div class="text-xs text-slate-500">{{ __('Catering this week') }}</div>
    <div class="text-2xl font-bold">{{ $mgmt['catering'] }}</div><div class="text-xs text-slate-500">events</div>
  </a>
  @endisset
</div>
@if(!empty($mgmt['sales']['by_branch']))
<div class="card p-4 mb-6">
  <div class="font-semibold mb-3">{{ __('Sales by branch today') }}</div>
  @php($max = max($mgmt['sales']['by_branch']) ?: 1)
  @foreach($mgmt['sales']['by_branch'] as $b => $v)
    <div class="flex items-center gap-3 text-sm mb-1.5"><div class="w-36 truncate">{{ $b }}</div>
      <div class="flex-1 bg-slate-100 rounded h-3"><div class="bg-brand-500 h-3 rounded" style="width:{{ round($v/$max*100) }}%"></div></div>
      <div class="w-24 text-right font-semibold">{{ $U::tk($v) }}</div></div>
  @endforeach
</div>
@endif
@endif

<div class="grid lg:grid-cols-3 gap-4">
  <div class="card p-4">
    <div class="font-semibold mb-2">{{ __('My shift today') }}</div>
    @if($home['shift'])
      <div class="text-lg font-bold">{{ $home['shift']['label'] }}</div>
      <div class="text-sm text-slate-500">{{ $home['location']['name'] ?? '' }}</div>
    @elseif($home['is_off'])<div class="text-slate-500">{{ __('Weekly off') }} 💤</div>
    @else<div class="text-slate-500">{{ __('No shift today') }}</div>@endif
    <div class="mt-3 text-sm">{{ __('Status') }}: {!! $U::badge($home['attendance']['status']) !!}
      @if($home['attendance']['check_in']) · in {{ $home['attendance']['check_in'] }} @endif
      @if($home['attendance']['check_out']) · out {{ $home['attendance']['check_out'] }} @endif</div>
    <p class="text-xs text-slate-400 mt-3">{{ __('Check in / out from the mobile app (GPS).') }}</p>
  </div>
  <div class="card p-4 lg:col-span-2">
    <div class="flex items-center justify-between mb-2"><div class="font-semibold">{{ __('My tasks') }}</div><a href="{{ route('tasks') }}" class="text-sm text-brand-700">{{ __('All') }} →</a></div>
    @forelse($home['tasks']->take(8) as $t)
      <div class="flex items-center justify-between py-1.5 border-b last:border-0 text-sm">
        <div>{{ $t->displayTitle() }} <span class="text-xs text-slate-400">· {{ $t->due_at->format('d M h:i A') }}</span></div>
        <div>{!! $U::badge($t->isOverdue() ? 'OVERDUE' : $t->status) !!}</div>
      </div>
    @empty <div class="text-sm text-slate-500">{{ __('No tasks') }} ✓</div> @endforelse
  </div>
</div>

@if($inbox->count())
<div class="card p-4 mt-4">
  <div class="flex items-center justify-between mb-2"><div class="font-semibold">{{ __('Waiting for your approval') }}</div><a href="{{ route('approvals') }}" class="text-sm text-brand-700">{{ __('Open inbox') }} →</a></div>
  @foreach($inbox as $s)
    <div class="flex items-center justify-between py-1.5 border-b last:border-0 text-sm">
      <div>{{ \App\Services\ApprovalService::titleOf($s->approvable) }}</div>
      <div class="font-semibold">{{ $U::tk(\App\Services\ApprovalService::amountOf($s->approvable)) }}</div>
    </div>
  @endforeach
</div>
@endif
@endsection
