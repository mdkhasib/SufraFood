@php
  $S = fn ($k, $d = null) => \App\Support\Features::setting($k, $d);
  $paper = (string) $S('receipt.paper', '80');
  $width = ['58' => '48mm', '80' => '72mm', 'A5' => '140mm'][$paper] ?? '72mm';
  $font = max(8, min(20, (int) $S('receipt.font_size', 12)));
  $logo = (int) $S('receipt.show_logo', 1) ? \App\Support\Brand::logoData() : null;
  $copies = ($preview ?? false) ? 1 :max(1, min(2, (int) $S('receipt.copies', 1)));
  $unpaid = $o->status !== 'PAID';
  $title = $unpaid ? __('BILL · not paid yet') : ($S('receipt.title') ?: 'INVOICE');
  $bn = (int) $S('receipt.show_bn_names', 0);
  $narrow = $paper === '58';
  $tendered = isset($tendered) && $tendered > 0 ? (float) $tendered : null;
  $paid = (float) $o->payments->sum('amount');
@endphp
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ $o->number }}</title>
<style>
  @page{margin:{{ $paper === 'A5' ? '10mm' : '0' }}; {{ $paper === 'A5' ? 'size:A5;' : '' }}}
  *{box-sizing:border-box}
  body{font-family:ui-monospace,Consolas,'Courier New',monospace;width:{{ $width }};margin:0 auto;padding:{{ $paper === 'A5' ? '0' : '2mm 0' }};font-size:{{ $font }}px;color:#000;background:#fff;line-height:1.3}
  .c{text-align:center}.r{text-align:right}.b{font-weight:700}.s{font-size:.85em}.xs{font-size:.78em}
  .hr{border-top:1px dashed #000;margin:4px 0}
  table{width:100%;border-collapse:collapse}td{padding:1px 0;vertical-align:top}td.r{padding-left:5px;white-space:nowrap}
  .logo{display:block;margin:0 auto 3px;max-width:60%;max-height:{{ $paper === '58' ? '18mm' : '24mm' }};object-fit:contain;filter:grayscale(1) contrast(1.4)}
  .name{font-size:1.35em;font-weight:800;letter-spacing:.02em}
  .title{display:inline-block;border:1px solid #000;padding:1px 8px;margin:3px 0;font-weight:700;letter-spacing:.08em}
  .total td{font-size:1.25em;font-weight:800;padding-top:2px}
  .copy{page-break-after:always}.copy:last-child{page-break-after:auto}
  .bar{position:sticky;top:0;background:#fef3c7;border-bottom:1px solid #f59e0b;padding:6px;font-family:system-ui,sans-serif;font-size:12px;text-align:center}
  .bar button{font:inherit;padding:3px 10px;margin-left:6px}
  @media print{.bar{display:none}}
</style></head>
<body @if(!($preview ?? false) && (int) $S('receipt.auto_print', 1)) onload="window.print()" @endif>
@if($preview ?? false)<div class="bar">{{ __('Preview with your current settings') }} · {{ $paper === 'A5' ? 'A5' : $paper.' mm' }}<button onclick="window.print()">🖨 {{ __('Test print') }}</button></div>
@elseif(!(int) $S('receipt.auto_print', 1))<div class="bar"><button onclick="window.print()">🖨 {{ __('Print') }}</button></div>@endif
@for($copy = 1; $copy <= $copies; $copy++)
<div class="copy">
  <div class="c">
    @if($logo)<img src="{{ $logo }}" class="logo" alt="">@endif
    <div class="name">{{ $S('company.name') }}</div>
    @if($S('company.tagline'))<div class="xs">{{ $S('company.tagline') }}</div>@endif
    <div class="s">{{ $o->location->name }}</div>
    @if((int) $S('receipt.show_address', 1) && ($o->location->address || $S('company.address')))<div class="xs">{{ $o->location->address ?: $S('company.address') }}</div>@endif
    @if((int) $S('receipt.show_phone', 1) && ($o->location->phone || $S('company.phone')))<div class="xs">☎ {{ $o->location->phone ?: $S('company.phone') }}</div>@endif
    @if((int) $S('receipt.show_vat_reg', 1) && $S('company.vat_reg'))<div class="xs">BIN / VAT Reg: {{ $S('company.vat_reg') }}</div>@endif
    <div class="title">{{ $title }}@if($copies > 1) · {{ $copy === 1 ? __('Customer copy') : __('Office copy') }}@endif</div>
  </div>
  <table class="s">
    <tr><td>{{ __('Bill no') }}: <b>{{ $o->number }}</b></td><td class="r">{{ ($o->paid_at ?? $o->created_at)?->format('d/m/Y h:i A') }}</td></tr>
    <tr><td>{{ str_replace('_', ' ', $o->order_type) }}{{ $o->table ? ' · '.__('Table').' '.$o->table->name : '' }}</td>
        <td class="r">@if((int) $S('receipt.show_cashier', 1)){{ __('Cashier') }}: {{ $o->cashier->name }}@endif</td></tr>
    @if((int) $S('receipt.show_customer', 1) && $o->customer_phone)<tr><td colspan="2">{{ __('Customer') }}: {{ $o->customer_phone }}</td></tr>@endif
  </table>
  <div class="hr"></div>
  <table>
    <tr class="b s"><td>{{ __('Item') }}</td><td class="r">{{ __('Qty') }}</td>@unless($narrow)<td class="r">{{ __('Rate') }}</td>@endunless<td class="r">{{ __('Amount') }}</td></tr>
    @foreach($o->lines as $l)
      <tr><td>{{ $l->item->name }}@if($bn && $l->item->name_bn)<div class="xs">{{ $l->item->name_bn }}</div>@endif</td>
        <td class="r">{{ rtrim(rtrim(number_format($l->qty, 2), '0'), '.') }}</td>@unless($narrow)<td class="r">{{ number_format($l->price, 0) }}</td>@endunless<td class="r">{{ number_format($l->total, 2) }}</td></tr>
    @endforeach
  </table>
  <div class="hr"></div>
  <table>
    <tr><td>{{ __('Subtotal') }}</td><td class="r">{{ number_format($o->subtotal, 2) }}</td></tr>
    @if($o->discount > 0)<tr><td>{{ __('Discount') }}</td><td class="r">-{{ number_format($o->discount, 2) }}</td></tr>@endif
    @if($o->service_charge > 0)<tr><td>{{ __('Service charge') }}</td><td class="r">{{ number_format($o->service_charge, 2) }}</td></tr>@endif
    <tr><td>VAT {{ rtrim(rtrim(number_format((float) $S('pos.vat_percent', 5), 2), '0'), '.') }}%</td><td class="r">{{ number_format($o->vat, 2) }}</td></tr>
  </table>
  <div class="hr"></div>
  <table class="total"><tr><td>{{ __('TOTAL') }}</td><td class="r">৳{{ number_format($o->total, 2) }}</td></tr></table>
  @if($o->payments->count())
    <div class="hr"></div>
    <table class="s">
      @foreach($o->payments as $p)<tr><td>{{ $p->paymentMethod->name }}{{ $p->reference ? ' · '.$p->reference : '' }}</td><td class="r">{{ number_format($p->amount, 2) }}</td></tr>@endforeach
      @if($tendered && $tendered > $paid)<tr><td>{{ __('Cash received') }}</td><td class="r">{{ number_format($tendered, 2) }}</td></tr><tr class="b"><td>{{ __('Change to return') }}</td><td class="r">{{ number_format($tendered - $paid, 2) }}</td></tr>@endif
    </table>
  @endif
  <div class="hr"></div>
  @if($S('receipt.footer'))<div class="c b">{{ $S('receipt.footer') }}</div>@endif
  @if($S('receipt.footer2'))<div class="c xs">{{ $S('receipt.footer2') }}</div>@endif
  <div class="c xs" style="margin-top:4px">Powered by Sufra GroupOps</div>
</div>
@endfor
</body></html>
