@extends('layouts.app')
@section('title', __('Audit log'))
@section('content')
<form class="mb-3 flex gap-2"><input name="q" value="{{ request('q') }}" class="inp !w-64" placeholder="action contains… (feature, approval, proxy, pos.void)"><button class="btn btn-light">Search</button></form>
<div class="card overflow-x-auto"><table class="t">
<tr><th>When</th><th>Who</th><th>Action</th><th>Entity</th><th>Details</th><th>Device / IP</th></tr>
@foreach($logs as $l)
<tr><td class="whitespace-nowrap">{{ $l->created_at->format('d M H:i') }}</td><td>{{ $l->user?->name ?? 'system' }}</td><td><code class="text-xs">{{ $l->action }}</code></td>
<td>{{ $l->entity_type }} #{{ $l->entity_id }}</td>
<td class="text-xs text-slate-600 max-w-md break-all">@if($l->before)<span class="text-red-600">{{ \Illuminate\Support\Str::limit(json_encode($l->before), 140) }}</span><br>@endif{{ \Illuminate\Support\Str::limit(json_encode($l->after), 180) }}</td>
<td class="text-xs text-slate-400">{{ \Illuminate\Support\Str::limit($l->device, 30) }} {{ $l->ip }}</td></tr>
@endforeach
</table></div>
<div class="mt-3">{{ $logs->links() }}</div>
@endsection
