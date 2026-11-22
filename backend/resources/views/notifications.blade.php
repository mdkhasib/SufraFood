@extends('layouts.app')
@section('title', __('Notifications'))
@section('content')
<div class="flex justify-end mb-3"><form method="post" action="{{ route('notifications.read') }}">@csrf<button class="btn btn-light btn-sm">{{ __('Mark all read') }}</button></form></div>
<div class="card divide-y">
  @forelse($items as $n)
    <div class="p-3 flex gap-3 {{ $n->read_at ? '' : 'bg-orange-50/60' }}">
      <div class="text-lg">{{ ['warning'=>'⚠️','success'=>'✅','approval'=>'📝','task'=>'📋','attendance'=>'🕘','delivery'=>'🚚','purchase'=>'🛒','request'=>'📦'][$n->type] ?? '🔔' }}</div>
      <div class="flex-1"><div class="font-semibold text-sm">{{ $n->title }}</div><div class="text-sm text-slate-600">{{ $n->body }}</div></div>
      <div class="text-xs text-slate-400 whitespace-nowrap">{{ $n->created_at->diffForHumans() }}</div>
    </div>
  @empty <div class="p-6 text-center text-slate-500">{{ __('No notifications') }}</div> @endforelse
</div>
<div class="mt-3">{{ $items->links() }}</div>
@endsection
