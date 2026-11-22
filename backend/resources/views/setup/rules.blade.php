@extends('layouts.app')
@section('title', __('Approval rules'))
@section('content')
@php($by = ['role'=>'Anyone with role','user'=>'Specific person','direct_manager'=>"Requester's manager",'location_manager'=>'Head of the location'])
<div class="card overflow-x-auto mb-6"><table class="t">
<tr><th>Type</th><th>Location</th><th>Amount ৳</th><th>Approval levels (in order)</th><th>SLA</th><th></th></tr>
@foreach($rules as $rule)
<tr><td class="font-semibold">{{ $rule->request_type }}</td><td>{{ $rule->location?->name ?? 'All' }}</td>
<td>{{ number_format($rule->min_amount) }} – {{ $rule->max_amount ? number_format($rule->max_amount) : '∞' }}</td>
<td>@forelse($rule->levels as $i=>$lv)<span class="badge bg-slate-100 mr-1">{{ $i+1 }}. {{ $lv['by']==='role' ? ($roles[$lv['value']] ?? $lv['value']) : ($lv['by']==='user' ? ($users[$lv['value']] ?? '?') : $by[$lv['by']]) }}</span>@empty <i class="text-slate-400">auto-approve</i>@endforelse</td>
<td>{{ $rule->sla_minutes ? $rule->sla_minutes.' min' : '—' }}</td>
<td><form method="post" action="{{ route('setup.rules.delete', $rule) }}" onsubmit="return confirm('Delete rule?')">@csrf @method('DELETE')<button class="btn btn-light btn-sm text-red-600">✕</button></form></td></tr>
@endforeach
</table></div>
<p class="text-xs text-slate-500 mb-2">No matching rule = auto-approved. A level whose approver is the requester is skipped. Personal approval limits (Employees screen) still apply.</p>
<form method="post" action="{{ route('setup.rules.save') }}" class="card p-5" x-data="{levels:[{by:'role'}]}">@csrf
  <div class="font-semibold mb-3">+ {{ __('New approval rule') }}</div>
  <div class="grid sm:grid-cols-5 gap-3">
    <div><label class="lbl">Request type</label><select name="request_type" class="inp">@foreach($types as $t)<option>{{ $t }}</option>@endforeach</select></div>
    <div><label class="lbl">Location</label><select name="location_id" class="inp"><option value="">All</option>@foreach($locations as $id=>$n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select></div>
    <div><label class="lbl">Min ৳</label><input name="min_amount" type="number" class="inp" value="0"></div>
    <div><label class="lbl">Max ৳ (blank = ∞)</label><input name="max_amount" type="number" class="inp"></div>
    <div><label class="lbl">SLA minutes / level</label><input name="sla_minutes" type="number" class="inp"></div>
  </div>
  <template x-for="(lv,i) in levels" :key="i">
    <div class="grid sm:grid-cols-4 gap-3 mt-3 items-end">
      <div><label class="lbl" x-text="'Level '+(i+1)"></label><select class="inp" :name="'levels['+i+'][by]'" x-model="lv.by">@foreach($by as $k=>$v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></div>
      <div x-show="lv.by==='role'"><label class="lbl">Role</label><select class="inp" :name="'levels['+i+'][role]'">@foreach($roles as $k=>$v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></div>
      <div x-show="lv.by==='user'"><label class="lbl">Person</label><select class="inp" :name="'levels['+i+'][user]'">@foreach($users as $k=>$v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></div>
      <button type="button" class="btn btn-light btn-sm w-fit" @click="levels.splice(i,1)">remove</button>
    </div>
  </template>
  <div class="mt-4 flex gap-2"><button type="button" class="btn btn-light" @click="levels.push({by:'role'})">+ level</button><button class="btn btn-primary">{{ __('Save rule') }}</button></div>
</form>
@endsection
