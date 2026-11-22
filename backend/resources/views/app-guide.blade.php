@php
  $server = \App\Support\Brand::serverUrl();
  $logo = \App\Support\Brand::logoUrl();
  $apk = \App\Support\Brand::apkExists();
  $size = \App\Support\Brand::apkSizeMb();
  $dl = url('/download/app');
  $guide = $server.'/app?download=1';
  $lanIp = \App\Support\Brand::lanIp();
  $port = request()->getPort();
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ __('Install the app') }} · {{ \App\Support\Brand::name() }}</title>
<link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;600;700&family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com/3.4.16"></script>
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.8/dist/cdn.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<style>body{font-family:Inter,'Hind Siliguri',system-ui,sans-serif}[x-cloak]{display:none!important}</style>
</head>
<body class="bg-slate-50 text-slate-800" x-data="{copied:false, started:false}">
<div class="max-w-3xl mx-auto px-4 py-6 md:py-10">
  <div class="flex items-center gap-3 mb-6">
    @if($logo)<img src="{{ $logo }}" class="h-12 w-12 rounded-xl object-contain bg-white border">@else<div class="h-12 w-12 rounded-xl bg-orange-600 text-white grid place-items-center font-bold text-lg">{{ \App\Support\Brand::initials() }}</div>@endif
    <div><div class="text-xl font-bold">{{ __('Install the Sufra GroupOps app') }}</div><div class="text-sm text-slate-500">{{ \App\Support\Brand::name() }} · Android</div></div>
    <a href="{{ auth()->check() ? route('home') : route('login') }}" class="ml-auto text-sm text-orange-700 font-semibold">{{ auth()->check() ? __('Back to dashboard') : __('Web login') }} →</a>
  </div>

  @if(!$apk)
    <div class="rounded-xl bg-red-50 border border-red-200 text-red-800 p-4 mb-5">{{ __('The app file has not been built on this server yet. Ask the Super Admin.') }}</div>
  @else
    <div class="rounded-2xl bg-white border p-5 mb-5 grid md:grid-cols-[1fr_auto] gap-5 items-center">
      <div>
        @if($download)<div class="text-green-700 font-semibold mb-1" x-show="started" x-cloak>✓ {{ __('Your download has started.') }}</div>@endif
        <div class="font-semibold">{{ __('Step 1 · Download') }}</div>
        <p class="text-sm text-slate-600 mt-1">{{ __('File') }}: <b>sufra-groupops.apk</b> ({{ $size }} MB). {{ __('If the download did not start, press the button. On a computer, scan the QR code with the phone camera instead.') }}</p>
        <a href="{{ $dl }}" class="inline-flex mt-3 rounded-lg bg-orange-600 hover:bg-orange-700 text-white font-semibold px-4 py-2">⬇ {{ __('Download the app') }}</a>
      </div>
      <div class="text-center"><div id="qr" class="inline-block bg-white p-2 border rounded-lg"></div><div class="text-[11px] text-slate-500 mt-1">{{ __('Scan with the phone') }}</div></div>
    </div>
  @endif

  <div class="rounded-2xl bg-white border p-5 mb-5">
    <div class="font-semibold">{{ __('Step 2 · Install') }}</div>
    <ol class="list-decimal ml-5 text-sm text-slate-700 mt-2 space-y-1">
      <li>{{ __('Open the downloaded file (Downloads or the notification).') }}</li>
      <li>{{ __('If Android says “For your security, your phone is not allowed to install unknown apps”, tap Settings → turn on “Allow from this source” → go back.') }}</li>
      <li>{{ __('Tap Install, then Open.') }}</li>
      <li class="text-amber-700">{{ __('Had the older “Ruchi” app? Uninstall it first. The new app installs separately.') }}</li>
    </ol>
  </div>

  <div class="rounded-2xl bg-white border-2 border-orange-300 p-5 mb-5">
    <div class="font-semibold">{{ __('Step 3 · Set the server address (one time)') }}</div>
    <p class="text-sm text-slate-600 mt-1">{{ __('On the app’s login screen tap') }} <b>“🖧 {{ __('Server address') }}”</b> {{ __('under the Sign in button and type exactly this:') }}</p>
    <div class="mt-3 flex flex-wrap items-center gap-2">
      <code id="srv" class="text-lg md:text-2xl font-bold bg-slate-900 text-orange-300 rounded-lg px-4 py-2 break-all">{{ $server }}</code>
      <button class="rounded-lg border px-3 py-2 text-sm font-semibold" @click="navigator.clipboard?.writeText(@js($server)); copied=true; setTimeout(()=>copied=false,1500)" x-text="copied ? '✓ {{ __('Copied') }}' : '📋 {{ __('Copy') }}'"></button>
    </div>
    <div class="mt-4 grid sm:grid-cols-2 gap-3 text-sm">
      <div class="rounded-lg bg-green-50 border border-green-200 p-3">
        <div class="font-semibold text-green-800 mb-1">✓ {{ __('Correct examples') }}</div>
        <div class="font-mono space-y-0.5"><div>http://{{ $lanIp ?? '192.168.110.205' }}:{{ $port }}</div><div>http://192.168.0.50:8090</div><div>https://ops.sufrafood.com</div></div>
        <div class="text-xs text-green-800 mt-1">{{ __('Office Wi-Fi: the server PC’s IP + port. Internet: your domain.') }}</div>
      </div>
      <div class="rounded-lg bg-red-50 border border-red-200 p-3">
        <div class="font-semibold text-red-800 mb-1">✗ {{ __('Common mistakes') }}</div>
        <div class="font-mono space-y-0.5"><div>http://192.168.110.205:80 <span class="font-sans text-xs">({{ __('wrong port') }})</span></div><div>http://192.168.110.205 <span class="font-sans text-xs">({{ __('no port') }})</span></div><div>http://localhost:8090 <span class="font-sans text-xs">({{ __('only works on the PC') }})</span></div></div>
        <div class="text-xs text-red-800 mt-1">{{ __('If you forget “http://” or add “/api”, the app fixes it for you.') }}</div>
      </div>
    </div>
    <div class="mt-4 rounded-lg bg-slate-50 border p-3 text-sm">
      <div class="font-semibold mb-1">{{ __('How it looks in the app') }}</div>
      <div class="max-w-xs rounded-xl border bg-white p-3 shadow-sm">
        <div class="text-[11px] text-slate-500">🖧 {{ __('Server address') }}</div>
        <div class="mt-1 rounded border-2 border-orange-500 px-2 py-1.5 font-mono text-sm">{{ $server }}</div>
        <div class="text-[10px] text-slate-400 mt-1">e.g. http://192.168.110.205:8090</div>
        <div class="mt-2 rounded bg-orange-600 text-white text-center text-xs py-1.5">{{ __('Test connection') }} → ✓ {{ \App\Support\Brand::name() }}</div>
      </div>
    </div>
  </div>

  <div class="rounded-2xl bg-white border p-5 mb-5">
    <div class="font-semibold">{{ __('Step 4 · Sign in') }}</div>
    <p class="text-sm text-slate-600 mt-1">{{ __('Choose “Employee ID” (e.g. E-2008) or “Mobile number” (e.g. 01700000038), type your password and tap Sign in. The app remembers the server address.') }}</p>
  </div>

  <div class="rounded-2xl bg-amber-50 border border-amber-200 p-5 text-sm">
    <div class="font-semibold mb-2">{{ __('Can’t connect?') }}</div>
    <ul class="list-disc ml-5 space-y-1 text-slate-700">
      <li>{{ __('Open the server address in the phone’s browser (Chrome). If the login page opens, the address is right; if not, the phone cannot reach the server.') }}</li>
      <li>{{ __('The phone must be on the same Wi-Fi as the server PC (mobile data cannot reach an office IP).') }}</li>
      <li>{{ __('The server PC must be on and running, and its firewall must allow the port') }} {{ $port }}.</li>
      <li>{{ __('If the server PC’s IP changed, use the new address shown on this page.') }}</li>
    </ul>
  </div>
</div>
<script>
  window.addEventListener('load', () => {
    try { if (window.QRCode) new QRCode(document.getElementById('qr'), { text: @js($guide), width: 132, height: 132 }); } catch (e) {}
    @if($download && $apk)
      setTimeout(() => { window.location.href = @js($dl); document.body._x_dataStack && (document.body._x_dataStack[0].started = true); }, 700);
    @endif
  });
</script>
</body>
</html>
