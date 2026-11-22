<div style="padding-left: {{ $depth * 14 }}px" class="py-1 {{ $depth ? 'border-l border-slate-200 ml-1' : '' }}">
  <a href="#team-{{ $t->id }}" class="flex items-start gap-2 text-sm hover:text-orange-700">
    <span class="mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full bg-{{ $t->color }}-500"></span>
    <span><span class="font-medium">{{ $t->displayName() }}</span>
      <span class="block text-[11px] text-slate-400">{{ $t->lead?->name }} · {{ $t->members->where('id', '!=', $t->lead_id)->count() }} {{ __('members') }}</span></span>
  </a>
</div>
@foreach(($byParent[$t->id] ?? collect()) as $child)
  @if($depth < 8) @include('org._team_tree', ['t' => $child, 'byParent' => $byParent, 'depth' => $depth + 1]) @endif
@endforeach
