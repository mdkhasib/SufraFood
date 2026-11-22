<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>@yield('title', 'Dashboard') · Sufra GroupOps</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com/3.4.16"></script>
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.8/dist/cdn.min.js"></script>
<script>
tailwind.config = { theme: { extend: {
  fontFamily: { sans: ['Inter', 'Hind Siliguri', 'system-ui', 'sans-serif'] },
  colors: { brand: { 50:'#fff7ed',100:'#ffedd5',200:'#fed7aa',500:'#f97316',600:'#ea580c',700:'#c2410c',800:'#9a3412',900:'#7c2d12' } }
} } }
</script>
<style>
  [x-cloak]{display:none!important}
  .btn{display:inline-flex;align-items:center;gap:.35rem;border-radius:.5rem;padding:.45rem .85rem;font-size:.875rem;font-weight:600;transition:.15s}
  .btn-primary{background:#ea580c;color:#fff}.btn-primary:hover{background:#c2410c}
  .btn-dark{background:#1f2937;color:#fff}.btn-dark:hover{background:#111827}
  .btn-light{background:#fff;color:#374151;border:1px solid #e5e7eb}.btn-light:hover{background:#f9fafb}
  .btn-green{background:#16a34a;color:#fff}.btn-green:hover{background:#15803d}
  .btn-red{background:#dc2626;color:#fff}.btn-red:hover{background:#b91c1c}
  .btn-sm{padding:.25rem .55rem;font-size:.78rem}
  .card{background:#fff;border:1px solid #eef0f3;border-radius:.85rem;box-shadow:0 1px 2px rgba(0,0,0,.03)}
  .inp{width:100%;border:1px solid #d1d5db;border-radius:.5rem;padding:.45rem .65rem;font-size:.875rem;background:#fff}
  .inp:focus{outline:2px solid #fdba74;border-color:#f97316}
  .lbl{display:block;font-size:.75rem;font-weight:600;color:#4b5563;margin-bottom:.2rem}
  table.t{width:100%;font-size:.85rem}
  table.t th{text-align:left;font-size:.72rem;text-transform:uppercase;letter-spacing:.03em;color:#6b7280;background:#f9fafb;padding:.55rem .7rem;border-bottom:1px solid #eef0f3}
  table.t td{padding:.55rem .7rem;border-bottom:1px solid #f3f4f6;vertical-align:top}
  table.t tr:hover td{background:#fffbf5}
  .badge{display:inline-block;border-radius:999px;padding:.08rem .5rem;font-size:.7rem;font-weight:600;white-space:nowrap}
</style>
@stack('head')
</head>
<body class="bg-slate-50 text-slate-800 antialiased" x-data="{side:false}">
@php($me = auth()->user())
@php($menu = \App\Support\Menu::for($me))
@php($unread = \App\Models\AppNotification::where('user_id', $me->id)->whereNull('read_at')->count())
<div class="min-h-screen flex">
  <aside class="fixed inset-y-0 left-0 z-40 w-64 bg-slate-900 text-slate-200 transform transition md:translate-x-0 overflow-y-auto" :class="side ? 'translate-x-0' : '-translate-x-full'">
    <div class="px-5 py-4 border-b border-slate-800">
      <div class="flex items-center gap-2">
        @php($brandLogo = \App\Support\Brand::logoUrl())
        @if($brandLogo)<img src="{{ $brandLogo }}" class="h-9 w-9 rounded-xl object-contain bg-white p-0.5" alt="">@else<div class="h-9 w-9 rounded-xl bg-brand-600 grid place-items-center font-bold text-white">{{ \App\Support\Brand::initials() }}</div>@endif
        <div><div class="font-bold text-white leading-tight">Sufra GroupOps</div><div class="text-[11px] text-slate-400">{{ \App\Support\Features::setting('company.name') }}</div></div>
      </div>
    </div>
    <nav class="px-3 py-3 space-y-4 text-sm">
      @foreach($menu as $group => $items)
        <div>
          <div class="px-2 mb-1 text-[10px] font-semibold uppercase tracking-wider text-slate-500">{{ __($group) }}</div>
          @foreach($items as $it)
            @php($url = \App\Support\Menu::url($it[1]))
            <a href="{{ $url }}" class="flex items-center justify-between rounded-lg px-2 py-1.5 {{ url()->current() === $url ? 'bg-brand-600 text-white' : 'hover:bg-slate-800' }}">
              <span>{{ __($it[0]) }}</span>
              @if($it[1] === 'notifications' && $unread)<span class="badge bg-red-500 text-white">{{ $unread }}</span>@endif
            </a>
          @endforeach
        </div>
      @endforeach
    </nav>
  </aside>
  <div class="fixed inset-0 bg-black/40 z-30 md:hidden" x-show="side" x-cloak @click="side=false"></div>
  <div class="flex-1 md:ml-64 min-w-0">
    <header class="sticky top-0 z-20 bg-white/90 backdrop-blur border-b border-slate-200">
      <div class="flex items-center gap-3 px-4 py-2.5">
        <button class="md:hidden btn btn-light btn-sm" @click="side=true">☰</button>
        <div class="font-semibold truncate">@yield('title', 'Dashboard')</div>
        <div class="ml-auto flex items-center gap-2">
          <div class="hidden sm:flex rounded-lg border overflow-hidden text-xs">
            <a href="{{ route('lang', 'en') }}" class="px-2 py-1 {{ app()->getLocale()==='en' ? 'bg-slate-800 text-white' : '' }}">EN</a>
            <a href="{{ route('lang', 'bn') }}" class="px-2 py-1 {{ app()->getLocale()==='bn' ? 'bg-slate-800 text-white' : '' }}">বাং</a>
          </div>
          <a href="{{ route('notifications') }}" class="relative btn btn-light btn-sm">🔔 @if($unread)<span class="absolute -top-1 -right-1 badge bg-red-500 text-white">{{ $unread }}</span>@endif</a>
          <a href="{{ route('profile') }}" class="hidden sm:block text-right leading-tight">
            <div class="text-sm font-semibold">{{ $me->displayName() }}</div>
            <div class="text-[11px] text-slate-500">{{ $me->is_super_admin ? 'Super Admin' : $me->role?->name }} · {{ $me->location?->name }}</div>
          </a>
          <form method="post" action="{{ route('logout') }}">@csrf<button class="btn btn-light btn-sm">{{ __('Logout') }}</button></form>
        </div>
      </div>
    </header>
    <main class="p-4 md:p-6 max-w-[1400px]">
      @if(session('ok'))<div class="mb-4 rounded-lg bg-green-50 border border-green-200 text-green-800 px-4 py-2 text-sm">✓ {{ session('ok') }}</div>@endif
      @if(session('error'))<div class="mb-4 rounded-lg bg-red-50 border border-red-200 text-red-800 px-4 py-2 text-sm">⚠ {{ session('error') }}</div>@endif
      @if($errors->any())<div class="mb-4 rounded-lg bg-red-50 border border-red-200 text-red-800 px-4 py-2 text-sm">@foreach($errors->all() as $e)<div>⚠ {{ $e }}</div>@endforeach</div>@endif
      @yield('content')
    </main>
    <footer class="border-t border-slate-200 mt-6 px-4 md:px-6 py-4 text-xs text-slate-500 flex flex-wrap items-center gap-x-4 gap-y-2 max-w-[1400px]">
      <span>© {{ date('Y') }} {{ \App\Support\Brand::name() }} · Sufra GroupOps</span>
      @if(\App\Support\Brand::apkExists())
        <a href="{{ route('app.guide', ['download' => 1]) }}" class="inline-flex items-center gap-1 rounded-lg bg-slate-900 text-white px-3 py-1.5 font-semibold hover:bg-slate-700">📱 {{ __('Download Android app') }} <span class="text-slate-400 font-normal">{{ \App\Support\Brand::apkSizeMb() }} MB</span></a>
      @endif
      <a href="{{ route('app.guide') }}" class="text-brand-700 font-semibold">{{ __('App setup guide') }}</a>
      <span class="ml-auto">{{ __('Server') }}: <span class="font-mono">{{ \App\Support\Brand::serverUrl() }}</span></span>
    </footer>
  </div>
</div>
@stack('scripts')
</body>
</html>
