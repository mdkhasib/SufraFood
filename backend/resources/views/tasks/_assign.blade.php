{{-- Assign-task modal. Open with $dispatch('open-new') or $dispatch('assign-to', {id, name}) (org chart). Needs $team (people) and $teams. --}}
@php($me = auth()->user())
@php($rem = $me->featureOn('tasks.reminders'))
@php($direct = $team->filter(fn ($p) => $p->manager_id === $me->id))
@php($leads = $team->filter(fn ($p) => $p->manager_id !== $me->id))
<div x-data="{open:false, pid:null, pname:'', tab:'people', q:'', prio:'NORMAL'}"
     @open-new.window="open=true; pid=null; pname=''; tab='people'"
     @assign-to.window="open=true; pid=$event.detail.id; pname=$event.detail.name; tab='people'"
     x-show="open" x-cloak class="fixed inset-0 z-50 bg-black/40 grid place-items-center p-4">
  <form method="post" action="{{ route('tasks.store') }}" class="card p-5 w-full max-w-2xl max-h-[92vh] overflow-y-auto" @click.outside="open=false">@csrf
    <div class="flex items-center mb-3"><div class="font-semibold text-lg flex-1">{{ __('Assign task') }}</div><button type="button" class="text-slate-400 text-xl" @click="open=false">&times;</button></div>
    <div class="grid sm:grid-cols-2 gap-3">
      <div class="sm:col-span-2"><label class="lbl">{{ __('Title') }}</label><input name="title" class="inp" required></div>
      <div class="sm:col-span-2"><label class="lbl">Title (বাংলা, optional)</label><input name="title_bn" class="inp"></div>
      <div class="sm:col-span-2"><label class="lbl">{{ __('Details') }}</label><textarea name="description" class="inp" rows="2"></textarea></div>
      <div><label class="lbl">{{ __('Due') }}</label><input type="datetime-local" name="due_at" class="inp" value="{{ now()->addHours(2)->format('Y-m-d\TH:i') }}" required></div>
      <div><label class="lbl">{{ __('Priority') }}</label>
        <div class="flex rounded-lg border overflow-hidden text-xs font-semibold">
          @foreach(['LOW'=>['Low','bg-slate-500'],'NORMAL'=>['Normal','bg-slate-700'],'HIGH'=>['High','bg-amber-500'],'URGENT'=>['Top priority','bg-red-600']] as $k=>[$l,$c])
            <label class="flex-1 text-center px-2 py-2 cursor-pointer" :class="prio==='{{ $k }}' ? '{{ $c }} text-white' : 'bg-white'"><input type="radio" name="priority" value="{{ $k }}" class="hidden" x-model="prio">{{ __($l) }}</label>
          @endforeach
        </div></div>
      @if($me->featureOn('tasks.proof_photo'))<label class="flex items-center gap-2 text-sm"><input type="checkbox" name="proof_required" value="1"> {{ __('Photo proof required') }}</label>@endif
    </div>

    {{-- who --}}
    <div class="mt-4">
      <template x-if="pid">
        <div class="rounded-lg border bg-orange-50 px-3 py-2 flex items-center gap-2 text-sm">
          <input type="hidden" name="assignees[]" :value="pid">
          <span>{{ __('To') }}: <b x-text="pname"></b></span><button type="button" class="ml-auto text-xs text-orange-700 font-semibold" @click="pid=null">{{ __('Change') }}</button>
        </div>
      </template>
      <div x-show="!pid">
        <div class="flex items-center gap-2 mb-2">
          <div class="flex rounded-lg border overflow-hidden text-sm">
            <button type="button" class="px-3 py-1.5" :class="tab==='people' ? 'bg-slate-800 text-white' : 'bg-white'" @click="tab='people'">{{ __('People') }} ({{ $team->count() }})</button>
            @if($teams->count())<button type="button" class="px-3 py-1.5" :class="tab==='teams' ? 'bg-slate-800 text-white' : 'bg-white'" @click="tab='teams'">{{ __('Teams') }} ({{ $teams->count() }})</button>@endif
          </div>
          <input x-show="tab==='people'" x-model="q" class="inp !py-1.5 flex-1" placeholder="{{ __('search name…') }}">
        </div>
        <div x-show="tab==='people'" class="max-h-60 overflow-y-auto border rounded-lg p-2 text-sm space-y-2">
          @foreach(['Reports to you directly' => $direct, 'Team leads & managers below you' => $leads] as $label => $list)
            @continue($list->isEmpty())
            <div><div class="text-[11px] uppercase tracking-wide text-slate-400 font-semibold mb-1">{{ __($label) }}</div>
            <div class="grid sm:grid-cols-2 gap-1">
            @foreach($list as $p)
              <label class="flex items-center gap-2 rounded px-1 hover:bg-slate-50" x-show="!q || '{{ strtolower($p->name.' '.$p->employee_code.' '.$p->position) }}'.includes(q.toLowerCase())">
                <input type="checkbox" name="assignees[]" value="{{ $p->id }}">
                <span>{{ $p->name }} <span class="text-xs text-slate-400">{{ $p->position }} · {{ $p->location?->code }}@if($p->reports_count) · {{ $p->reports_count }} {{ __('under') }}@endif</span></span>
              </label>
            @endforeach
            </div></div>
          @endforeach
          @if($team->isEmpty())<div class="text-slate-500 text-center py-4">{{ __('Nobody reports to you yet.') }}</div>@endif
          <p class="text-[11px] text-slate-400 pt-1 border-t">{{ __('Front-line staff are reached through their team lead, a team, or the org chart.') }}</p>
        </div>
        <div x-show="tab==='teams'" x-cloak class="border rounded-lg p-2 text-sm">
          <div class="max-h-48 overflow-y-auto grid sm:grid-cols-2 gap-1">
            @foreach($teams as $tm)
              <label class="flex items-center gap-2 rounded px-1 hover:bg-slate-50"><input type="checkbox" name="teams[]" value="{{ $tm->id }}">
                <span><span class="inline-block h-2 w-2 rounded-full bg-{{ $tm->color }}-500"></span> {{ $tm->name }} <span class="text-xs text-slate-400">{{ __('lead') }} {{ $tm->lead?->name }} · {{ $tm->members_count }}</span></span></label>
            @endforeach
          </div>
          <div class="mt-2 pt-2 border-t flex flex-wrap gap-4 text-xs">
            <label class="flex items-center gap-1"><input type="radio" name="team_mode" value="LEAD" checked> {{ __('Give it to the team lead (they share it out)') }}</label>
            <label class="flex items-center gap-1"><input type="radio" name="team_mode" value="EACH"> {{ __('Every member gets their own task') }}</label>
          </div>
        </div>
      </div>
    </div>

    {{-- reminders & alerts --}}
    @if($rem)
    <div class="mt-4 rounded-lg border p-3 bg-slate-50">
      <div class="text-sm font-semibold mb-2">{{ __('Reminders & alerts') }}</div>
      <div class="grid sm:grid-cols-2 gap-3 text-sm">
        <div><label class="lbl">{{ __('Remind before due time') }}</label>
          <select name="remind_before_minutes" class="inp"><option value="">{{ __('No') }}</option>@foreach([10,15,30,60,120] as $m)<option value="{{ $m }}" @selected($m==(int)\App\Support\Features::setting('tasks.default_remind_before',30))>{{ $m < 60 ? $m.' min' : ($m/60).' h' }}</option>@endforeach</select></div>
        <div><label class="lbl">{{ __('If late, remind again every') }}</label>
          <select name="remind_every_minutes" class="inp">
            <option value="">{{ __('Auto: by priority') }}</option>@foreach([10,15,30,60] as $m)<option value="{{ $m }}">{{ $m }} min</option>@endforeach
          </select>
          <div class="text-[11px] text-slate-500 mt-1" x-show="prio==='URGENT'">{{ __('Top priority: every') }} {{ \App\Support\Features::setting('tasks.urgent_remind_every',15) }} min</div>
          <div class="text-[11px] text-slate-500 mt-1" x-show="prio==='HIGH'">{{ __('High: every') }} {{ \App\Support\Features::setting('tasks.high_remind_every',30) }} min</div>
          <div class="text-[11px] text-slate-500 mt-1" x-show="prio==='NORMAL' || prio==='LOW'">{{ __('Normal/low: no repeat unless you choose one') }}</div>
        </div>
        <label class="flex items-center gap-2"><input type="checkbox" name="notify_on_done" value="1" checked> {{ __('Notify me when it is finished') }}</label>
        <label class="flex items-center gap-2"><input type="checkbox" name="notify_on_start" value="1"> {{ __('Notify me when it is started') }}</label>
      </div>
    </div>
    @endif
    <div class="mt-4 flex gap-2"><button class="btn btn-primary">{{ __('Assign') }}</button><button type="button" class="btn btn-light" @click="open=false">{{ __('Cancel') }}</button></div>
  </form>
</div>
