@extends('layouts.app')
@section('title', __('Catering events'))
@section('content')
@php($U = \App\Support\Ui::class)
<div class="flex mb-4"><a href="{{ route('cat.create') }}" class="btn btn-primary ml-auto">+ {{ __('New event') }}</a></div>
<div class="card overflow-x-auto"><table class="t">
<tr><th>No.</th><th>Event</th><th>Date</th><th>Venue</th><th>Guests</th><th>Value</th><th>Paid</th><th>Status</th></tr>
@foreach($events as $e)
<tr class="cursor-pointer" onclick="location='{{ route('cat.show', $e) }}'"><td class="font-semibold text-brand-700">{{ $e->number }}</td><td>{{ $e->title }}<div class="text-xs text-slate-400">{{ $e->client_name }}</div></td>
<td>{{ $e->event_at->format('D d M, h:i A') }}<div class="text-xs text-slate-400">{{ $e->event_at->diffForHumans() }}</div></td><td>{{ $e->venue }}</td><td>{{ $e->guests }}</td><td>{{ $U::tk($e->total) }}</td><td>{{ $U::tk($e->paid) }}</td><td>{!! $U::badge($e->status) !!}</td></tr>
@endforeach
</table></div>
@endsection
