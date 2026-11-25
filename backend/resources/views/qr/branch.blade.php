<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<title>{{ $l->name }} · {{ __('Menu') }}</title>
<link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;600;700&family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com/3.4.16"></script>
<style>body{font-family:Inter,'Hind Siliguri',system-ui,sans-serif}</style></head>
<body class="bg-orange-50/40 text-slate-800">
<div class="max-w-md mx-auto px-4 py-8 text-center">
  @if($logo = \App\Support\Brand::logoUrl())<img src="{{ $logo }}" class="h-16 w-16 mx-auto rounded-2xl object-contain border bg-white" alt="">@else<div class="h-16 w-16 mx-auto rounded-2xl bg-orange-600 text-white grid place-items-center font-bold text-xl">{{ \App\Support\Brand::initials() }}</div>@endif
  <div class="text-xl font-bold mt-3">{{ \App\Support\Brand::name() }}</div>
  <div class="text-slate-500">{{ $l->name }}</div>
  <div class="mt-6 font-semibold">Which table are you sitting at?</div>
  <div class="text-xs text-slate-500">The number is on the table stand.</div>
  <div class="grid grid-cols-3 gap-3 mt-4">
    @forelse($tables as $t)
      <a href="{{ route('qr.table', $t->qr_token) }}" class="rounded-2xl bg-white border py-4 text-lg font-bold hover:border-orange-500 active:scale-95 transition">{{ $t->name }}</a>
    @empty
      <div class="col-span-3 text-slate-400">No tables set up yet.</div>
    @endforelse
  </div>
</div>
</body></html>
