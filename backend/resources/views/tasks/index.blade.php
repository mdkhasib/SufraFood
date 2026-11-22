@extends('layouts.app')
@section('title', __('Tasks'))
@section('content')
@php($U = \App\Support\Ui::class)
@php($me = auth()->user())
<div class="flex flex-wrap items-center gap-2 mb-4" x-data>
  @if($me->hasPerm('tasks.view_team'))
  <div class="flex rounded-lg border overflow-hidden text-sm">
    @foreach(['mine'=>'My tasks','team'=>'Team tasks'] as $k=>$l)<a href="?scope={{ $k }}&status={{ $status }}" class="px-3 py-1.5 {{ $scope===$k ? 'bg-slate-800 text-white' : 'bg-white' }}">{{ __($l) }}</a>@endforeach
  </div>
  @endif
  <div class="flex rounded-lg border overflow-hidden text-sm">
    @foreach(['open'=>'Open','overdue'=>'Overdue','review'=>'To review','mine_assigned'=>'Assigned by me','done'=>'Done','all'=>'All'] as $k=>$l)<a href="?scope={{ $scope }}&status={{ $k }}" class="px-3 py-1.5 {{ $status===$k ? 'bg-brand-600 text-white' : 'bg-white' }}">{{ __($l) }}</a>@endforeach
  </div>
  @if($me->hasPerm('tasks.assign'))<button class="btn btn-primary ml-auto" @click="$dispatch('open-new')">+ {{ __('Assign task') }}</button>@endif
</div>

<div class="card overflow-x-auto">
<table class="t">
<tr><th>{{ __('Task') }}</th><th>{{ __('Assigned to') }}</th><th>{{ __('Due') }}</th><th>{{ __('Status') }}</th><th>{{ __('Proof') }}</th><th></th></tr>
@forelse($tasks as $t)
<tr>
  <td><div class="font-medium">{{ $t->displayTitle() }} @if($t->template_id)<span class="badge bg-slate-100">recurring</span>@endif @if($t->priority==='URGENT')<span class="badge bg-red-600 text-white">{{ __('top priority') }}</span>@elseif($t->priority==='HIGH')<span class="badge bg-amber-100 text-amber-800">{{ __('high') }}</span>@endif @if($t->team)<span class="badge bg-{{ $t->team->color }}-100 text-{{ $t->team->color }}-800">{{ $t->team->name }}</span>@endif</div>
    <div class="text-xs text-slate-500">{{ $t->description }} @if($t->assigner) · by {{ $t->assigner->name }} @endif @if($t->rework_count) · rework ×{{ $t->rework_count }} @endif
      @if($t->remind_before_minutes || $t->repeatEvery())<span class="text-amber-700"> · {{ __('reminders') }}: @if($t->remind_before_minutes){{ $t->remind_before_minutes }}m {{ __('before') }}@endif @if($t->repeatEvery()) {{ __('every') }} {{ $t->repeatEvery() }}m {{ __('if late') }}@endif @if($t->reminders_sent)({{ $t->reminders_sent }} {{ __('sent') }})@endif</span>@endif
      @if($t->notify_on_done && $t->assigned_by)<span class="text-slate-400"> · {{ __('done alert on') }}</span>@endif</div></td>
  <td>{{ $t->assignee?->name }}<div class="text-xs text-slate-400">{{ $t->assignee?->location?->name }}</div></td>
  <td class="whitespace-nowrap">{{ $t->due_at->format('d M, h:i A') }}
    @if($t->completed_at)<div class="text-xs {{ $t->completed_at->lte($t->due_at) ? 'text-green-700' : 'text-red-600' }}">done {{ $t->completed_at->format('h:i A') }} {{ $t->completed_at->lte($t->due_at) ? '✓ on time' : 'late' }}</div>@endif</td>
  <td>{!! $U::badge($t->isOverdue() ? 'OVERDUE' : $t->status) !!}</td>
  <td>@if($t->proof_photo)<a href="{{ asset('storage/'.$t->proof_photo) }}" target="_blank"><img src="{{ asset('storage/'.$t->proof_photo) }}" class="h-10 w-14 object-cover rounded border"></a>@elseif($t->proof_required)<span class="text-xs text-slate-400">📷 needed</span>@endif
    @if($t->proof_note)<div class="text-xs text-slate-500">“{{ $t->proof_note }}”</div>@endif</td>
  <td class="whitespace-nowrap text-right" x-data="{done:false}">
    @if($t->assignee_id === $me->id && $t->status==='ASSIGNED')<form method="post" action="{{ route('tasks.action', [$t,'start']) }}" class="inline">@csrf<button class="btn btn-light btn-sm">▶ {{ __('Start') }}</button></form>@endif
    @if($t->assignee_id === $me->id && in_array($t->status,['ASSIGNED','STARTED']))
      <button class="btn btn-green btn-sm" @click="done=!done">✓ {{ __('Done') }}</button>
      <form x-show="done" x-cloak method="post" enctype="multipart/form-data" action="{{ route('tasks.action', [$t,'done']) }}" class="mt-2 flex flex-col gap-1 items-end">@csrf
        @if($t->proof_required)<input type="file" name="photo" accept="image/*" capture="environment" class="text-xs" required>@endif
        <input name="note" class="inp !w-48" placeholder="note (optional)"><button class="btn btn-green btn-sm">{{ __('Submit') }}</button></form>
    @endif
    @if(in_array($t->status,['ASSIGNED','STARTED']) && $t->assignee_id !== $me->id && ($t->assigned_by === $me->id || $me->is_super_admin || in_array($t->assignee_id, $me->subordinateIdsCached())))
      <form method="post" action="{{ route('tasks.action', [$t,'remind']) }}" class="inline">@csrf<button class="btn btn-light btn-sm" title="{{ __('Send a reminder now') }}">&#128276; {{ __('Remind') }}</button></form>
    @endif
    @if($t->status==='SUBMITTED' && $t->assignee_id !== $me->id && $me->hasPerm('tasks.assign'))
      <form method="post" action="{{ route('tasks.action', [$t,'approve']) }}" class="inline">@csrf<button class="btn btn-green btn-sm">✓ {{ __('Approve') }}</button></form>
      <form method="post" action="{{ route('tasks.action', [$t,'rework']) }}" class="inline">@csrf<input type="hidden" name="note" value="Not good enough, please redo"><button class="btn btn-light btn-sm">↺ {{ __('Rework') }}</button></form>
    @endif
  </td>
</tr>
@empty <tr><td colspan="6" class="text-center text-slate-500 py-8">{{ __('No tasks') }}</td></tr> @endforelse
</table>
</div>
<div class="mt-3">{{ $tasks->links() }}</div>

@if($me->hasPerm('tasks.assign'))
  @include('tasks._assign')
@endif
@endsection
