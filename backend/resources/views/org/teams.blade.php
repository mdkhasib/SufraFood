@extends('layouts.app')
@section('title', __('Teams'))
@section('content')
@php($colors = ['slate','red','orange','amber','emerald','teal','sky','blue','indigo','violet','pink'])
@php($peopleJs = $people->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'code' => $p->employee_code, 'pos' => $p->position, 'loc' => $p->location_id, 'team' => $p->team?->name, 'team_id' => $p->team_id])->values())
@php($byParent = $teams->groupBy(fn ($t) => (int) $t->parent_id))
<div x-data="teamPage()" @edit-team.window="edit($event.detail)">
<div class="card p-3 mb-4 flex flex-wrap items-center gap-2">
  <a href="{{ route('org') }}" class="btn btn-light btn-sm">&#8592; {{ __('Org chart') }}</a>
  @if($locations->count() > 1)
    <form><select name="location_id" class="inp !py-1.5 !w-auto" onchange="this.form.submit()"><option value="">{{ __('All locations') }}</option>@foreach($locations as $l)<option value="{{ $l->id }}" @selected(request('location_id')==$l->id)>{{ $l->name }}</option>@endforeach</select></form>
  @endif
  <span class="text-sm text-slate-500">{{ $teams->count() }} {{ __('teams') }}</span>
  @if($canManage)<button type="button" class="btn btn-primary ml-auto" @click="edit(null)">+ {{ __('New team') }}</button>@endif
</div>

<div class="grid lg:grid-cols-3 gap-4">
  {{-- team tree --}}
  <div class="card p-4 lg:col-span-1 self-start">
    <div class="font-semibold mb-2">{{ __('Team tree') }}</div>
    @php($roots = $teams->filter(fn ($t) => ! $t->parent_id || ! $teams->contains('id', $t->parent_id)))
    @foreach($roots as $t) @include('org._team_tree', ['t' => $t, 'byParent' => $byParent, 'depth' => 0]) @endforeach
  </div>

  {{-- team cards --}}
  <div class="lg:col-span-2 grid sm:grid-cols-2 gap-4 self-start">
  @forelse($teams as $t)
    <div class="card overflow-hidden border-t-4 border-t-{{ $t->color }}-500" id="team-{{ $t->id }}">
      <div class="p-4">
        <div class="flex items-start gap-2">
          <div class="flex-1">
            <div class="font-semibold">{{ $t->displayName() }}</div>
            <div class="text-xs text-slate-500">{{ $t->location?->name }} @if($t->department) · {{ $t->department->name }} @endif @if($t->parent) · {{ __('under') }} {{ $t->parent->name }} @endif</div>
          </div>
          @if($canManage)
            <button type="button" class="btn btn-light btn-sm" @click="edit(@js(['id' => $t->id, 'name' => $t->name, 'name_bn' => $t->name_bn, 'location_id' => $t->location_id, 'department_id' => $t->department_id, 'lead_id' => $t->lead_id, 'parent_id' => $t->parent_id, 'color' => $t->color, 'description' => $t->description, 'members' => $t->members->pluck('id')->diff([$t->lead_id])->values()]))">{{ __('Edit') }}</button>
          @endif
        </div>
        <div class="mt-3 flex items-center gap-2 text-sm">
          <span class="badge bg-{{ $t->color }}-100 text-{{ $t->color }}-800">{{ __('Lead') }}</span>
          <span class="font-medium">{{ $t->lead?->name ?? '—' }}</span><span class="text-xs text-slate-400">{{ $t->lead?->position }}</span>
        </div>
        @php($members = $t->members->where('id', '!=', $t->lead_id))
        <div class="mt-2 text-xs text-slate-500">{{ $members->count() }} {{ __('members') }}</div>
        <div class="mt-1 flex flex-wrap gap-1">
          @foreach($members as $m)<span class="rounded-full border px-2 py-0.5 text-xs" title="{{ $m->position }}">{{ $m->name }}@if($m->manager_id !== $t->lead_id)<span class="text-amber-600" title="{{ __('Reports to someone else') }}">*</span>@endif</span>@endforeach
        </div>
        @if($t->description)<p class="mt-2 text-xs text-slate-500">{{ $t->description }}</p>@endif
      </div>
    </div>
  @empty
    <div class="card p-8 text-center text-slate-500 sm:col-span-2">{{ __('No teams yet.') }}</div>
  @endforelse
  </div>
</div>
<p class="text-xs text-slate-400 mt-3">* {{ __('member reports to someone other than the team lead') }}</p>

@if($canManage)
<div x-show="open" x-cloak class="fixed inset-0 z-50 bg-black/40 grid place-items-center p-4">
  <form method="post" action="{{ route('teams.save') }}" class="card p-5 w-full max-w-2xl max-h-[92vh] overflow-y-auto" @click.outside="open=false">@csrf
    <input type="hidden" name="id" :value="f.id">
    <div class="flex items-center mb-3"><div class="font-semibold text-lg flex-1" x-text="f.id ? '{{ __('Edit team') }}' : '{{ __('New team') }}'"></div>
      <template x-if="f.id"><button type="submit" form="del-team" class="btn btn-light btn-sm text-red-600" onclick="return confirm('{{ __('Remove this team? People stay, they just leave the team.') }}')">{{ __('Delete') }}</button></template></div>
    <div class="grid sm:grid-cols-2 gap-3">
      <div><label class="lbl">{{ __('Team name') }}</label><input name="name" class="inp" x-model="f.name" required></div>
      <div><label class="lbl">নাম (বাংলা)</label><input name="name_bn" class="inp" x-model="f.name_bn"></div>
      <div><label class="lbl">{{ __('Location') }}</label><select name="location_id" class="inp" x-model="f.location_id" required>@foreach($locations as $l)<option value="{{ $l->id }}">{{ $l->name }}</option>@endforeach</select></div>
      <div><label class="lbl">{{ __('Department') }}</label><select name="department_id" class="inp" x-model="f.department_id"><option value="">—</option>@foreach($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select></div>
      <div><label class="lbl">{{ __('Team lead') }}</label><select name="lead_id" class="inp" x-model="f.lead_id"><option value="">—</option>
        <template x-for="p in people" :key="p.id"><option :value="p.id" x-text="p.name + ' — ' + (p.pos||'')" :selected="p.id == f.lead_id"></option></template></select></div>
      <div><label class="lbl">{{ __('Sits under (parent team)') }}</label><select name="parent_id" class="inp" x-model="f.parent_id"><option value="">{{ __('Top level') }}</option>@foreach($allTeams as $at)<option value="{{ $at->id }}">{{ $at->name }}</option>@endforeach</select></div>
      <div class="sm:col-span-2"><label class="lbl">{{ __('Colour') }}</label>
        <div class="flex flex-wrap gap-2">@foreach($colors as $c)<label class="cursor-pointer"><input type="radio" name="color" value="{{ $c }}" class="hidden" x-model="f.color"><span class="block h-7 w-7 rounded-full bg-{{ $c }}-500" :class="f.color==='{{ $c }}' ? 'ring-4 ring-offset-1 ring-{{ $c }}-300' : ''"></span></label>@endforeach</div></div>
      <div class="sm:col-span-2"><label class="lbl">{{ __('Description') }}</label><input name="description" class="inp" x-model="f.description"></div>
    </div>
    <div class="mt-4">
      <div class="flex items-center gap-2 mb-1"><label class="lbl !mb-0 flex-1">{{ __('Members') }} (<span x-text="f.members.length"></span>)</label>
        <label class="text-xs flex items-center gap-1"><input type="checkbox" x-model="allLoc"> {{ __('show other locations') }}</label>
        <input x-model="q" class="inp !py-1 !w-40" placeholder="{{ __('search…') }}"></div>
      <div class="max-h-56 overflow-y-auto border rounded-lg p-2 grid sm:grid-cols-2 gap-1 text-sm">
        <template x-for="p in people.filter(p => (allLoc || p.loc == f.location_id) && p.id != f.lead_id && (!q || (p.name+' '+p.code+' '+(p.pos||'')).toLowerCase().includes(q.toLowerCase())))" :key="p.id">
          <label class="flex items-center gap-2 rounded px-1 hover:bg-slate-50"><input type="checkbox" name="members[]" :value="p.id" x-model.number="f.members">
            <span><span x-text="p.name"></span> <span class="text-xs text-slate-400" x-text="(p.pos||'') + (p.team && p.team_id != f.id ? ' · now in ' + p.team : '')"></span></span></label>
        </template>
      </div>
      <label class="mt-2 flex items-center gap-2 text-sm"><input type="checkbox" name="report_to_lead" value="1" checked> {{ __('Members report to the team lead (updates the org chart)') }}</label>
    </div>
    <div class="mt-4 flex gap-2"><button class="btn btn-primary">{{ __('Save team') }}</button><button type="button" class="btn btn-light" @click="open=false">{{ __('Cancel') }}</button></div>
  </form>
  <form id="del-team" method="post" :action="'{{ url('/teams') }}/' + f.id">@csrf @method('DELETE')</form>
</div>
@endif
</div>
<script>
  function teamPage() {
    const blank = {id: null, name: '', name_bn: '', location_id: '{{ $locations->first()?->id }}', department_id: '', lead_id: '', parent_id: '', color: 'orange', description: '', members: []};
    return {
      open: false, q: '', allLoc: false, people: @json($peopleJs), f: {...blank},
      edit(t) { this.f = t ? {...blank, ...t, members: (t.members || []).map(Number)} : {...blank}; this.open = true; },
    };
  }
</script>
@endsection
