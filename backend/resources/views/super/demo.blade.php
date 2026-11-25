@extends('layouts.app')
@section('title', __('Demo accounts'))
@section('content')
<div class="card p-4 mb-4 text-sm text-slate-600">
  Sign in with the <b>ID</b> or the <b>mobile number</b>. Demo password: <code class="font-mono bg-slate-100 px-1 rounded">123456</code> (until the person changes it).
  Only the Super Admin sees this page. Change these passwords (Employees → edit) before real staff use the system.
</div>
<div class="card p-4 mb-4">
  <div class="font-semibold mb-2">Key accounts</div>
  <div class="overflow-x-auto"><table class="t">
    <tr><th>ID</th><th>Mobile</th><th>Who</th><th>Name</th></tr>
    @foreach($key as $code => $label)
      @php($u = $users->firstWhere('employee_code', $code))
      @if($u)<tr><td class="font-mono font-semibold">{{ $code }}</td><td class="font-mono text-slate-500">{{ $u->phone }}</td><td>{{ $label }}</td><td>{{ $u->name }}</td></tr>@endif
    @endforeach
  </table></div>
</div>
<form class="mb-3 flex gap-2"><input name="q" value="{{ request('q') }}" class="inp !w-64" placeholder="name, ID, role or branch…"><button class="btn btn-light">Search</button></form>
<div class="card overflow-x-auto"><table class="t">
  <tr><th>ID</th><th>Mobile</th><th>Name</th><th>Role</th><th>Location</th></tr>
  @foreach($list as $u)
    <tr><td class="font-mono font-semibold">{{ $u->employee_code }}</td><td class="font-mono text-slate-500">{{ $u->phone }}</td><td>{{ $u->name }}</td><td>{{ $u->is_super_admin ? 'Super Admin' : $u->role?->name }}</td><td>{{ $u->location?->name }}</td></tr>
  @endforeach
</table></div>
@endsection
