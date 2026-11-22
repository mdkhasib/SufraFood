@extends('layouts.app')
@section('title', __('Company admins'))
@section('content')
<p class="text-sm text-slate-500 mb-3">Company Admins manage users, roles & permissions, approval rules, limits and master data — only inside the features you have activated. They cannot switch features.</p>
<div class="card overflow-x-auto"><table class="t">
<tr><th>ID</th><th>Name</th><th>Role</th><th>Location</th><th>Company Admin</th><th></th></tr>
@foreach($users as $u)
<tr><td>{{ $u->employee_code }}</td><td class="font-medium">{{ $u->name }}</td><td>{{ $u->role?->name }}</td><td>{{ $u->location?->name }}</td>
<td>{!! $u->is_company_admin ? \App\Support\Ui::badge('ACTIVE') : '—' !!}</td>
<td><form method="post" action="{{ route('super.admins.toggle', $u) }}">@csrf<button class="btn btn-sm {{ $u->is_company_admin ? 'btn-light' : 'btn-dark' }}">{{ $u->is_company_admin ? 'Remove admin' : 'Make Company Admin' }}</button></form></td></tr>
@endforeach
</table></div>
@endsection
