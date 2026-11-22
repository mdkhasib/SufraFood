@extends('layouts.app')
@section('title', __('Delegations'))
@section('content')
<form method="post" action="{{ route('setup.delegations.save') }}" class="card p-4 grid sm:grid-cols-5 gap-3 items-end mb-5">@csrf
  <div><label class="lbl">Approver on leave</label><select name="from_user_id" class="inp">@foreach($users as $id=>$n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select></div>
  <div><label class="lbl">Delegate to</label><select name="to_user_id" class="inp">@foreach($users as $id=>$n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select></div>
  <div><label class="lbl">From</label><input type="date" name="from_date" class="inp" value="{{ today()->toDateString() }}"></div>
  <div><label class="lbl">To</label><input type="date" name="to_date" class="inp" value="{{ today()->addDays(3)->toDateString() }}"></div>
  <button class="btn btn-primary">+ {{ __('Add') }}</button>
</form>
<div class="card"><table class="t"><tr><th>From</th><th>To</th><th>Dates</th><th></th></tr>
@foreach($items as $d)<tr><td>{{ $d->fromUser->name }}</td><td>{{ $d->toUser->name }}</td><td>{{ $d->from_date->format('d M') }} – {{ $d->to_date->format('d M Y') }}</td>
<td><form method="post" action="{{ route('setup.delegations.delete', $d) }}">@csrf @method('DELETE')<button class="btn btn-light btn-sm">✕</button></form></td></tr>@endforeach
</table></div>
@endsection
