@extends('layouts.app')
@section('title', __('Journal'))
@section('content')
@php($U = \App\Support\Ui::class)
<form class="flex gap-2 mb-4"><input name="q" value="{{ request('q') }}" class="inp !w-64" placeholder="search memo / number"><select name="account_id" class="inp !w-64"><option value="">All accounts</option>@foreach($accounts as $a)<option value="{{ $a->id }}" @selected(request('account_id')==$a->id)>{{ $a->code }} {{ $a->name }}</option>@endforeach</select><button class="btn btn-light">Filter</button></form>
<div class="card overflow-x-auto mb-5"><table class="t">
<tr><th>Date</th><th>No.</th><th>Memo</th><th>Lines</th><th class="text-right">Amount</th><th>Source</th></tr>
@foreach($entries as $e)
<tr class="cursor-pointer" onclick="location='{{ route('acc.entry', $e) }}'"><td class="whitespace-nowrap">{{ $e->date->format('d M Y') }}</td><td class="text-brand-700">{{ $e->number }}</td><td>{{ $e->memo }}</td>
<td class="text-xs">@foreach($e->lines as $l){{ $l->account->code }} {{ $l->debit > 0 ? 'Dr '.number_format($l->debit) : 'Cr '.number_format($l->credit) }}<br>@endforeach</td>
<td class="text-right">{{ $U::tk($e->lines->sum('debit')) }}</td><td class="text-xs">{{ $e->source_type }}</td></tr>
@endforeach
</table></div>
<div class="mb-5">{{ $entries->links() }}</div>
@if(auth()->user()->hasPerm('acc.manage'))
<form method="post" action="{{ route('acc.post') }}" class="card p-4" x-data="{lines:[{},{}], dr(){return this.lines.reduce((s,l)=>s+(+l.debit||0),0)}, cr(){return this.lines.reduce((s,l)=>s+(+l.credit||0),0)}}">@csrf
  <div class="font-semibold mb-3">+ Manual journal entry</div>
  <div class="grid sm:grid-cols-3 gap-3 mb-3"><input type="date" name="date" value="{{ today()->toDateString() }}" class="inp"><input name="memo" class="inp sm:col-span-2" placeholder="memo" required></div>
  <template x-for="(l,i) in lines" :key="i"><div class="grid grid-cols-12 gap-2 mb-2">
    <select class="inp col-span-4" :name="'lines['+i+'][account_id]'"><option value="">account</option>@foreach($accounts as $a)<option value="{{ $a->id }}">{{ $a->code }} {{ $a->name }}</option>@endforeach</select>
    <select class="inp col-span-3" :name="'lines['+i+'][money_account_id]'"><option value="">money a/c (if cash/bank)</option>@foreach($money as $m)<option value="{{ $m->id }}">{{ $m->name }}</option>@endforeach</select>
    <select class="inp col-span-2" :name="'lines['+i+'][location_id]'"><option value="">location</option>@foreach($locations as $l)<option value="{{ $l->id }}">{{ $l->code }}</option>@endforeach</select>
    <input class="inp col-span-1" type="number" step="any" :name="'lines['+i+'][debit]'" x-model="l.debit" placeholder="Dr">
    <input class="inp col-span-1" type="number" step="any" :name="'lines['+i+'][credit]'" x-model="l.credit" placeholder="Cr">
    <button type="button" class="btn btn-light col-span-1" @click="lines.splice(i,1)">✕</button></div></template>
  <div class="flex items-center gap-3"><button type="button" class="btn btn-light btn-sm" @click="lines.push({})">+ line</button>
    <span class="text-sm" :class="Math.abs(dr()-cr())<0.01 ? 'text-green-700' : 'text-red-600'" x-text="'Dr '+dr().toFixed(2)+' / Cr '+cr().toFixed(2)"></span>
    <button class="btn btn-primary ml-auto" :disabled="Math.abs(dr()-cr())>=0.01 || dr()==0">Post</button></div>
</form>
@endif
@endsection
