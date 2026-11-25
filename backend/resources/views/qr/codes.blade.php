@extends('layouts.app')
@section('title', __('Table QR codes'))
@section('content')
@php($brand = \App\Support\Brand::name())
@php($base = rtrim(\App\Support\Brand::serverUrl(), '/'))
<style>
  @media print {
    aside, header, nav, footer, .no-print { display:none !important }
    main { padding:0 !important } body { background:#fff !important }
    .qr-grid { gap:6mm !important } .qr-card { break-inside:avoid; border:1px solid #000 !important; box-shadow:none !important }
  }
</style>
<div class="no-print flex flex-wrap items-center gap-2 mb-4">
  @if($branches->count() > 1)
    <form><select name="location" class="inp !w-56" onchange="this.form.submit()">@foreach($branches as $b)<option value="{{ $b->id }}" @selected($b->id === $branch->id)>{{ $b->name }}</option>@endforeach</select></form>
  @endif
  <div class="text-sm text-slate-500">{{ __('Print, cut and put one on each table. Customers scan it with the phone camera.') }}</div>
  <button class="btn btn-primary ml-auto" onclick="window.print()">🖨 {{ __('Print all') }}</button>
</div>
@if(str_starts_with($base, 'http://localhost') || str_starts_with($base, 'http://127.'))
  <div class="no-print rounded-lg bg-amber-50 border border-amber-200 text-amber-800 text-sm p-3 mb-4">{{ __('These codes point to') }} <b>{{ $base }}</b>, {{ __('which phones cannot open. Set the real address in Company settings → Mobile app (server address) before printing.') }}</div>
@endif

<div class="qr-grid grid sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
  @foreach($tables as $t)
    <div class="qr-card card p-4 text-center {{ $t->active ? '' : 'opacity-50' }}">
      <div class="text-xs text-slate-500">{{ $brand }} · {{ $branch->name }}</div>
      <div class="text-3xl font-black mt-1">{{ __('Table') }} {{ $t->name }}</div>
      <div class="qr inline-block bg-white p-2 mt-2" data-url="{{ $base }}/t/{{ $t->qr_token }}"></div>
      <div class="font-semibold mt-1">📱 {{ __('Scan to see the menu & order') }}</div>
      <div class="text-xs text-slate-500">{{ __('Call a waiter or ask for the bill from your phone') }}</div>
      <div class="no-print mt-3 flex justify-center gap-2 text-xs">
        <a href="{{ route('qr.table', $t->qr_token) }}" target="_blank" class="btn btn-light btn-sm">{{ __('Open') }} ↗</a>
        <form method="post" action="{{ route('qr.regenerate', $t) }}" onsubmit="return confirm('{{ __('Make a new code for this table? The old printed code stops working.') }}')">@csrf<button class="btn btn-light btn-sm">↻ {{ __('New code') }}</button></form>
      </div>
      @unless($t->active)<div class="no-print text-xs text-red-600 mt-1">{{ __('table switched off') }}</div>@endunless
    </div>
  @endforeach
  @if((int) \App\Support\Features::setting('qr.branch_qr', 1) && $branch->qr_token)
    <div class="qr-card card p-4 text-center border-2 border-dashed">
      <div class="text-xs text-slate-500">{{ $brand }}</div>
      <div class="text-2xl font-black mt-1">{{ $branch->name }}</div>
      <div class="qr inline-block bg-white p-2 mt-2" data-url="{{ $base }}/b/{{ $branch->qr_token }}"></div>
      <div class="font-semibold mt-1">📱 {{ __('Scan, choose your table, order') }}</div>
      <div class="text-xs text-slate-500">{{ __('For the door / counter (customer picks the table)') }}</div>
    </div>
  @endif
</div>
@if($tables->isEmpty())<div class="card p-8 text-center text-slate-400">{{ __('No tables at this branch. Add them in Company setup → Dining tables.') }}</div>@endif
@push('scripts')
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>document.querySelectorAll('.qr').forEach(el => { try { new QRCode(el, { text: el.dataset.url, width: 170, height: 170, correctLevel: QRCode.CorrectLevel.M }) } catch (e) { el.textContent = el.dataset.url } });</script>
@endpush
@endsection
