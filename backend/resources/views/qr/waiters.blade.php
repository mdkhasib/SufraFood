@extends('layouts.app')
@section('title', __('Table waiters'))
@section('content')
<div class="flex flex-wrap items-center gap-3 mb-3">
  @if($branches->count() > 1)
    <form method="get"><select name="location" class="inp !w-56" onchange="this.form.submit()">
      @foreach($branches as $b)<option value="{{ $b->id }}" @selected($branch->id === $b->id)>{{ $b->name }}</option>@endforeach</select></form>
  @else
    <div class="text-lg font-bold">{{ $branch?->name }}</div>
  @endif
  <div class="text-sm text-slate-500">{{ __('Tick who looks after each table. One waiter can have many tables, and a table can have more than one waiter.') }}</div>
</div>
<div class="card p-3 mb-3 text-sm text-slate-600">
  {{ __('When a customer at a table calls a waiter, asks for the bill or water, orders from the QR menu or pays by bKash / Nagad, every waiter of that table gets it as a task (My tasks / mobile app). Start = “I am coming”, Done = finished. Tables with no waiter go to the cashier and managers on the Table calls screen as before.') }}
</div>
@if(! $tables->count())
  <div class="card p-6 text-slate-500">{{ __('This branch has no tables yet: add them in Company setup → Dining tables.') }}</div>
@elseif(! $staff->count())
  <div class="card p-6 text-slate-500">{{ __('No staff at this branch to choose from.') }}</div>
@else
<form method="post" action="{{ route('tables.waiters.save') }}" x-data>@csrf
  <input type="hidden" name="location" value="{{ $branch->id }}">
  <div class="card overflow-x-auto">
    <table class="t text-sm">
      <tr><th class="sticky left-0 bg-white z-10">{{ __('Table') }}</th>
        @foreach($staff as $s)
          <th class="text-center whitespace-nowrap"><div>{{ $s->name }}</div><div class="text-[10px] font-normal text-slate-400">{{ $s->role?->name }}</div>
            <button type="button" class="text-[10px] text-brand-700 font-normal" @click="document.querySelectorAll('[data-w=\'{{ $s->id }}\']').forEach(c=>c.checked=true)">{{ __('all') }}</button></th>
        @endforeach
        <th>{{ __('Now') }}</th></tr>
      @foreach($tables as $t)
        @php($has = $t->waiters->pluck('id')->all())
        <tr class="{{ $t->active ? '' : 'opacity-50' }}">
          <td class="sticky left-0 bg-white font-semibold whitespace-nowrap">{{ $t->name }} <span class="text-xs text-slate-400">{{ $t->seats }} {{ __('seats') }}</span></td>
          @foreach($staff as $s)
            <td class="text-center"><input type="checkbox" class="h-4 w-4" name="w[{{ $t->id }}][]" value="{{ $s->id }}" data-w="{{ $s->id }}" @checked(in_array($s->id, $has))></td>
          @endforeach
          <td class="text-xs text-slate-500 whitespace-nowrap">{{ $t->waiters->pluck('name')->join(', ') ?: '—' }}</td>
        </tr>
      @endforeach
    </table>
  </div>
  <div class="mt-3 flex gap-2"><button class="btn btn-dark">{{ __('Save waiters') }}</button>
    <button type="button" class="btn btn-light" @click="document.querySelectorAll('input[type=checkbox][data-w]').forEach(c=>c.checked=false)">{{ __('Clear all') }}</button></div>
</form>
@endif
@endsection
