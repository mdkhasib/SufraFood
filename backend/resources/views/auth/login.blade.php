<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in · Sufra GroupOps</title>
<link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;600&family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com/3.4.16"></script>
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.8/dist/cdn.min.js"></script>
<style>body{font-family:Inter,'Hind Siliguri',sans-serif}</style>
</head>
<body class="min-h-screen bg-gradient-to-br from-orange-50 via-white to-slate-100 grid place-items-center p-4">
<div class="w-full max-w-md">
  <div class="text-center mb-6">
    @php($brandLogo = \App\Support\Brand::logoUrl())
    @if($brandLogo)<img src="{{ $brandLogo }}" class="mx-auto h-16 max-w-[180px] object-contain" alt="">@else<div class="mx-auto h-14 w-14 rounded-2xl bg-orange-600 grid place-items-center text-2xl font-bold text-white">{{ \App\Support\Brand::initials() }}</div>@endif
    <h1 class="mt-3 text-2xl font-bold text-slate-800">Sufra GroupOps</h1>
    <div class="text-sm font-semibold text-orange-700">{{ \App\Support\Brand::name() }}</div>
    <p class="text-sm text-slate-500">Restaurants · Central Kitchen · Catering · Warehouse</p>
  </div>
  <form method="post" action="{{ url('/login') }}" class="bg-white rounded-2xl shadow-sm border p-6 space-y-4">
    @csrf
    @if($errors->any())<div class="rounded-lg bg-red-50 text-red-700 text-sm px-3 py-2">{{ $errors->first() }}</div>@endif
    <div x-data="{mode: '{{ old('mode', 'id') }}'}">
      <div class="grid grid-cols-2 rounded-lg border overflow-hidden text-sm font-semibold mb-3">
        <button type="button" class="py-2" :class="mode==='id' ? 'bg-orange-600 text-white' : 'bg-white text-slate-600'" @click="mode='id'; $nextTick(() => $refs.login.focus())">Employee ID</button>
        <button type="button" class="py-2" :class="mode==='phone' ? 'bg-orange-600 text-white' : 'bg-white text-slate-600'" @click="mode='phone'; $nextTick(() => $refs.login.focus())">Mobile number</button>
      </div>
      <input type="hidden" name="mode" :value="mode">
      <label class="block text-xs font-semibold text-slate-600 mb-1" x-text="mode==='id' ? 'Employee ID' : 'Mobile number'"></label>
      <input name="login" x-ref="login" value="{{ old('login') }}" class="w-full border rounded-lg px-3 py-2" :inputmode="mode==='phone' ? 'tel' : 'text'" :placeholder="mode==='id' ? 'e.g. E-2001' : 'e.g. 01700000005'" autofocus required>
    </div>
    <div><label class="block text-xs font-semibold text-slate-600 mb-1">Password</label>
      <input type="password" name="password" class="w-full border rounded-lg px-3 py-2" required></div>
    <button class="w-full rounded-lg bg-orange-600 hover:bg-orange-700 text-white font-semibold py-2.5">Sign in</button>
  </form>
  <footer class="mt-5 text-center text-xs text-slate-500 space-y-2">
    @if(\App\Support\Brand::apkExists())
      <a href="{{ route('app.guide', ['download' => 1]) }}" class="inline-flex items-center gap-2 rounded-xl bg-slate-900 text-white px-4 py-2 text-sm font-semibold hover:bg-slate-700">📱 Download Android app <span class="text-slate-400 font-normal text-xs">{{ \App\Support\Brand::apkSizeMb() }} MB</span></a>
    @endif
    <div><a href="{{ route('app.guide') }}" class="text-orange-700 font-semibold">How to set up the app (server address)</a></div>
    <div>© {{ date('Y') }} {{ \App\Support\Brand::name() }}</div>
  </footer>
</div>
</body>
</html>
