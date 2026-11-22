{{-- One person in the indented list view. --}}
@php($c = $n['team']['color'] ?? 'slate')
<div x-data="{open: {{ $depth < 2 ? 'true' : 'false' }}}" class="org-row" data-search="{{ strtolower($n['name'].' '.$n['code'].' '.$n['position'].' '.($n['team']['name'] ?? '')) }}">
  <div class="flex items-center gap-2 py-1.5 border-b border-slate-100" style="padding-left: {{ $depth * 22 }}px">
    @if($n['children'])<button type="button" class="w-5 text-slate-500" @click="open=!open" x-text="open ? '▾' : '▸'"></button>@else<span class="w-5 text-slate-300 text-center">•</span>@endif
    <span class="h-6 w-6 shrink-0 rounded-full grid place-items-center text-[10px] font-bold bg-{{ $c }}-100 text-{{ $c }}-800">{{ collect(explode(' ', $n['name']))->map(fn ($w) => mb_substr($w, 0, 1))->take(2)->join('') }}</span>
    <div class="min-w-0 flex-1">
      <span class="font-medium text-sm">{{ app()->getLocale()==='bn' && $n['name_bn'] ? $n['name_bn'] : $n['name'] }}</span>
      <span class="text-xs text-slate-500">{{ $n['position'] }} · {{ $n['code'] }} · {{ $n['location'] }}</span>
      @if($n['team'])<span class="badge bg-{{ $c }}-50 text-{{ $c }}-700 text-[10px]">{{ $n['team']['name'] }}</span>@endif
      @if($n['below'])<span class="text-[11px] text-slate-400">{{ $n['below'] }} {{ __('below') }}</span>@endif
    </div>
    <div class="flex gap-1 text-[11px] shrink-0">
      @if($n['can_assign'])<button type="button" class="px-1.5 py-0.5 rounded bg-orange-600 text-white font-semibold" @click="$dispatch('assign-to', {id: {{ $n['id'] }}, name: @js($n['name'])})">+ {{ __('Task') }}</button>@endif
      @if($n['can_edit'])<button type="button" class="px-1.5 py-0.5 rounded border" @click="$dispatch('reports-to', {id: {{ $n['id'] }}, name: @js($n['name']), manager: {{ $n['manager_id'] ?? 'null' }}})">{{ __('Reports to') }}</button>@endif
    </div>
  </div>
  @if($n['children'])
    <div x-show="open" x-cloak>
      @foreach($n['children'] as $ch)
        @include('org._row', ['n' => $ch, 'depth' => $depth + 1])
      @endforeach
    </div>
  @endif
</div>
