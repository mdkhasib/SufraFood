{{-- One person in the org chart (top-down). $n = node array, $depth --}}
@php($c = $n['team']['color'] ?? 'slate')
<li x-data="{open: {{ $depth < 2 ? 'true' : 'false' }}}">
  <div class="org-card inline-block text-left rounded-xl border bg-white shadow-sm w-48 align-top border-t-4 border-t-{{ $c }}-500" data-search="{{ strtolower($n['name'].' '.$n['code'].' '.$n['position'].' '.($n['team']['name'] ?? '')) }}">
    <div class="p-2.5">
      <div class="flex items-start gap-2">
        <div class="h-8 w-8 shrink-0 rounded-full grid place-items-center text-xs font-bold bg-{{ $c }}-100 text-{{ $c }}-800">{{ collect(explode(' ', $n['name']))->map(fn ($w) => mb_substr($w, 0, 1))->take(2)->join('') }}</div>
        <div class="min-w-0">
          <div class="font-semibold text-sm leading-tight truncate" title="{{ $n['name'] }}">{{ app()->getLocale()==='bn' && $n['name_bn'] ? $n['name_bn'] : $n['name'] }}</div>
          <div class="text-[11px] text-slate-500 leading-tight truncate" title="{{ $n['position'] }}">{{ $n['position'] }}</div>
          <div class="text-[10px] text-slate-400">{{ $n['code'] }} · {{ $n['location'] }}</div>
        </div>
      </div>
      @if($n['team'])<div class="mt-1.5"><span class="badge bg-{{ $c }}-50 text-{{ $c }}-700 text-[10px]">{{ $n['team']['name'] }}</span></div>@endif
      <div class="mt-2 flex items-center gap-1 text-[11px]">
        @if($n['can_assign'])<button type="button" class="px-1.5 py-0.5 rounded bg-orange-600 text-white font-semibold" @click="$dispatch('assign-to', {id: {{ $n['id'] }}, name: @js($n['name'])})">+ {{ __('Task') }}</button>@endif
        @if($n['can_edit'])<button type="button" class="px-1.5 py-0.5 rounded border" title="{{ __('Change who this person reports to') }}" @click="$dispatch('reports-to', {id: {{ $n['id'] }}, name: @js($n['name']), manager: {{ $n['manager_id'] ?? 'null' }}})">{{ __('Reports to') }}</button>@endif
        @if($n['below'])<a href="?root={{ $n['id'] }}" class="px-1.5 py-0.5 rounded border" title="{{ __('Show only this branch of the tree') }}">&#8982;</a>@endif
      </div>
    </div>
    @if($n['children'])
      <button type="button" class="w-full border-t text-[11px] py-1 text-slate-600 hover:bg-slate-50 rounded-b-xl" @click="open=!open">
        <span x-text="open ? '{{ __('Hide') }}' : '{{ __('Show') }}'"></span> {{ count($n['children']) }} {{ __('direct') }} · {{ $n['below'] }} {{ __('total') }}
      </button>
    @endif
  </div>
  @if($n['children'])
    <ul x-show="open" x-cloak>
      @foreach($n['children'] as $ch)
        @include('org._node', ['n' => $ch, 'depth' => $depth + 1])
      @endforeach
    </ul>
  @endif
</li>
