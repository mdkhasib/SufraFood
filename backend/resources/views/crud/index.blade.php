@extends('layouts.app')
@section('title', __($cfg['title']))
@section('content')
<div class="flex flex-wrap items-center gap-2 mb-4">
  <form class="flex flex-wrap gap-2">
    @if(!empty($cfg['search']))<input name="q" value="{{ request('q') }}" class="inp !w-56" placeholder="{{ __('Search') }}…">@endif
    @foreach($cfg['filter'] ?? [] as $col => $f)
      <select name="{{ $col }}" class="inp !w-44" onchange="this.form.submit()"><option value="">{{ $f[0] }}: all</option>
        @foreach(($f[1])() as $k=>$v)<option value="{{ $k }}" @selected(request($col)==$k)>{{ $v }}</option>@endforeach</select>
    @endforeach
    <button class="btn btn-light">{{ __('Filter') }}</button>
  </form>
  <a href="{{ route('crud.create', $cfg['res']) }}" class="btn btn-primary ml-auto">+ {{ __('Add') }}</a>
</div>
<div class="card overflow-x-auto">
<table class="t">
  <tr>@foreach($cfg['list'] as $k=>$label)<th>{{ $label }}</th>@endforeach<th></th></tr>
  @foreach($rows as $row)
  <tr>
    @foreach($cfg['list'] as $k=>$label)
      @php($v = $k === '_balance' ? \App\Support\Ui::tk($row->balance()) : data_get($row, $k))
      <td>@if($k === '_image')@if($row->image)<img src="{{ $row->imageUrl() }}" class="h-10 w-10 rounded-lg object-cover border" alt="">@else<div class="h-10 w-10 rounded-lg bg-slate-100 grid place-items-center text-slate-300">📷</div>@endif @elseif(is_bool($v)) {{ $v ? '✓' : '—' }} @elseif($k==='status' || $k==='type') {!! \App\Support\Ui::badge($v) !!} @else {{ $v instanceof \Carbon\Carbon ? $v->format('d M Y') : $v }} @endif
        @if($k==='name' && $cfg['res']==='users' && $row->is_company_admin) <span class="badge bg-slate-800 text-white">Company Admin</span>@endif</td>
    @endforeach
    <td class="whitespace-nowrap text-right">
      <a href="{{ route('crud.edit', [$cfg['res'], $row->id]) }}" class="btn btn-light btn-sm">{{ __('Edit') }}</a>
      @if($cfg['res']==='users' && $row->device_id)
        <form method="post" action="{{ route('crud.reset-device', $row->id) }}" class="inline">@csrf<button class="btn btn-light btn-sm" title="Allow a new phone">📱 reset</button></form>
      @endif
      @if($cfg['res']!=='users')
      <form method="post" action="{{ route('crud.destroy', [$cfg['res'], $row->id]) }}" class="inline" onsubmit="return confirm('Delete?')">@csrf @method('DELETE')<button class="btn btn-light btn-sm text-red-600">✕</button></form>
      @endif
    </td>
  </tr>
  @endforeach
</table>
</div>
<div class="mt-3">{{ $rows->links() }}</div>
@endsection
