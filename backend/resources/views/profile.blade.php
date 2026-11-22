@extends('layouts.app')
@section('title', __('My profile'))
@section('content')
<div class="grid md:grid-cols-2 gap-4">
  <div class="card p-5 text-sm space-y-1.5">
    <div class="text-lg font-bold">{{ $u->name }} <span class="text-slate-400 font-normal">{{ $u->name_bn }}</span></div>
    <div>ID: <b>{{ $u->employee_code }}</b> · Phone: {{ $u->phone }}</div>
    <div>{{ $u->position }} · {{ $u->is_super_admin ? 'Super Admin' : $u->role?->name }} {{ $u->is_company_admin ? '· Company Admin' : '' }}</div>
    <div>{{ $u->location?->name }} · {{ $u->department?->name }} · reports to {{ $u->manager?->name ?? '—' }}</div>
    <div class="pt-2 text-xs text-slate-500">Permissions: {{ count($u->effectivePermissions()) }} · Features on for me: {{ count($u->enabledFeatures()) }}</div>
  </div>
  <form method="post" action="{{ route('profile.password') }}" class="card p-5 space-y-3">@csrf
    <div class="font-semibold">{{ __('Change password') }}</div>
    <div><label class="lbl">Current</label><input type="password" name="current" class="inp" required></div>
    <div><label class="lbl">New</label><input type="password" name="password" class="inp" required></div>
    <div><label class="lbl">Repeat new</label><input type="password" name="password_confirmation" class="inp" required></div>
    <button class="btn btn-primary">{{ __('Save') }}</button>
  </form>
</div>
@endsection
