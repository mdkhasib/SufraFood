@extends('layouts.app')
@section('title', $data['number'])
@section('content')
@php($U = \App\Support\Ui::class)
@php($A = $data['actions'])
<div class="card p-4 mb-4 flex flex-wrap gap-6 items-center">
  <div><div class="text-xs text-slate-500">{{ $data['type_label'] }} · {{ strtolower($data['source']) }}</div><div class="text-xl font-bold">{{ $data['number'] }} {!! $U::badge($data['status']) !!} @if($data['priority']==='URGENT'){!! $U::badge('URGENT') !!}@endif</div></div>
  <div><div class="text-xs text-slate-500">From → To</div><b>{{ $data['from'] }} → {{ $data['to'] }}</b></div>
  <div><div class="text-xs text-slate-500">Needed by</div><b>{{ $data['needed_by'] }}</b></div>
  <div><div class="text-xs text-slate-500">Requested by</div><b>{{ $data['requester'] }}</b> <span class="text-xs">{{ $data['created'] }}</span></div>
  <div><div class="text-xs text-slate-500">Estimate</div><b>{{ $U::tk($data['amount']) }}</b></div>
</div>
@if($data['waiting_on'])<div class="mb-4 rounded-lg bg-blue-50 border border-blue-200 text-blue-900 px-4 py-2 text-sm">⏳ <b>Waiting:</b> {{ $data['waiting_on'] }}</div>@endif
@if($data['reject_reason'])<div class="mb-4 rounded-lg bg-red-50 border border-red-200 text-red-800 px-4 py-2 text-sm">Rejected: {{ $data['reject_reason'] }}</div>@endif
@if($data['notes'])<div class="mb-4 text-sm text-slate-600">📝 {{ $data['notes'] }}</div>@endif

<div class="grid lg:grid-cols-3 gap-4">
<div class="lg:col-span-2 space-y-4">
  {{-- lines + actions that take quantities --}}
  @php($qtyAction = collect(['accept','dispatch','receive','update-draft'])->first(fn($a)=>in_array($a,$A)))
  <form method="post" action="{{ $qtyAction ? route('req.action', [$req, $qtyAction]) : '#' }}" class="card overflow-x-auto">@csrf
    <table class="t"><tr><th>Item</th><th>Requested</th><th>Approved</th><th>Sent</th><th>Received</th>@if(in_array('accept',$A) || in_array('shortage',$A))<th>Stock at {{ $data['to'] }}</th>@endif @if($qtyAction)<th class="bg-orange-50">{{ ucfirst(str_replace('-',' ',$qtyAction)) }} qty</th>@endif</tr>
    @foreach($data['lines'] as $l)
      <tr><td class="font-medium">{{ $l['name'] }} <span class="text-xs text-slate-400">{{ $l['unit'] }}</span>@if($l['note'])<div class="text-xs text-slate-400">{{ $l['note'] }}</div>@endif</td>
      <td>{{ $U::q($l['qty_requested']) }}</td><td>{{ $l['qty_approved'] !== null ? $U::q($l['qty_approved']) : '' }}</td><td>{{ $l['qty_sent'] !== null ? $U::q($l['qty_sent']) : '' }}</td><td>{{ $l['qty_received'] !== null ? $U::q($l['qty_received']) : '' }}</td>
      @if(in_array('accept',$A) || in_array('shortage',$A))<td class="{{ $l['stock_at_fulfiller'] !== null && $l['stock_at_fulfiller'] < $l['qty_requested'] ? 'text-red-600 font-semibold' : '' }}">{{ $l['stock_at_fulfiller'] !== null ? $U::q($l['stock_at_fulfiller']) : '' }}</td>@endif
      @if($qtyAction)
        @php($default = match($qtyAction){ 'accept' => $l['stock_at_fulfiller'] !== null && !in_array($req->type,['REPAIR','FOOD_ORDER']) ? min($l['qty_requested'], max(0,$l['stock_at_fulfiller'])) : $l['qty_requested'], 'dispatch' => $l['qty_approved'] ?? $l['qty_requested'], 'receive' => $l['qty_sent'] ?? $l['qty_approved'] ?? $l['qty_requested'], default => $l['qty_requested'] })
        <td class="bg-orange-50"><input type="number" step="any" min="0" name="qtys[{{ $l['id'] }}]" value="{{ $U::q($default) }}" class="inp !w-28 !py-1"></td>
      @endif</tr>
    @endforeach
    </table>
    @if($qtyAction)
      <div class="p-3 flex flex-wrap gap-2 items-end border-t">
        @if($qtyAction==='dispatch')
          <div><label class="lbl">Driver (optional)</label><select name="driver_id" class="inp !w-48"><option value="">Internal / no driver</option>@foreach($extra['drivers'] ?? [] as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select></div>
          <div><label class="lbl">Vehicle</label><input name="vehicle" class="inp !w-48" placeholder="Van 1"></div>
        @endif
        @if(in_array($qtyAction,['accept','receive']))<div class="flex-1"><label class="lbl">Note</label><input name="note" class="inp" placeholder="{{ $qtyAction==='receive' ? 'damage / shortage note' : 'optional' }}"></div>@endif
        <button class="btn btn-green">✓ {{ ['accept'=>'Accept','dispatch'=>'Dispatch (stock out)','receive'=>'Confirm received (stock in)','update-draft'=>'Save quantities'][$qtyAction] }}</button>
      </div>
    @endif
  </form>

  <div class="flex flex-wrap gap-2">
    @if(in_array('submit',$A))<form method="post" action="{{ route('req.action', [$req,'submit']) }}">@csrf<button class="btn btn-primary">Submit now</button></form>@endif
    @if(in_array('shortage',$A))<form method="post" action="{{ route('req.action', [$req,'shortage']) }}">@csrf<button class="btn btn-dark">🛒 Raise purchase for shortage</button></form>@endif
    @if(in_array('complete',$A))<form method="post" action="{{ route('req.action', [$req,'complete']) }}" class="flex gap-1">@csrf<input name="note" class="inp !w-56" placeholder="what was done"><button class="btn btn-green">Mark repaired</button></form>@endif
    @if(in_array('reject',$A))<form method="post" action="{{ route('req.action', [$req,'reject']) }}" class="flex gap-1">@csrf<input name="reason" class="inp !w-56" placeholder="reason" required><button class="btn btn-red">Reject</button></form>@endif
    @if(in_array('cancel',$A))<form method="post" action="{{ route('req.action', [$req,'cancel']) }}" onsubmit="return confirm('Cancel this request?')">@csrf<button class="btn btn-light">Cancel request</button></form>@endif
  </div>

  @if($myStep)
  <form method="post" action="{{ route('approvals.act', $myStep) }}" class="card p-4 flex flex-wrap gap-2 items-center border-amber-300">@csrf
    <b>Your approval (level {{ $myStep->level }}):</b><input name="comment" class="inp !w-64" placeholder="comment / reason">
    <button name="decision" value="approve" class="btn btn-green">✓ Approve</button><button name="decision" value="reject" class="btn btn-red">✕ Reject</button>
  </form>
  @endif

  @if(in_array('assign',$A))
  <form method="post" action="{{ route('req.action', [$req,'assign']) }}" class="card p-4 grid sm:grid-cols-3 gap-3" x-data="{f:'{{ $extra['fundings']->keys()->first() }}'}">@csrf
    <div class="sm:col-span-3 font-semibold">🛒 Assign a buyer</div>
    <div><label class="lbl">Buyer</label><select name="buyer_id" class="inp">@foreach($extra['buyers'] as $b)<option value="{{ $b['id'] }}">{{ $b['name'] }} · wallet ৳{{ number_format($b['wallet']) }}</option>@endforeach</select></div>
    <div><label class="lbl">Deadline</label><select class="inp" onchange="this.nextElementSibling.value=this.value"><option value="{{ now()->addHour()->format('Y-m-d\TH:i') }}">1 hour</option><option value="{{ now()->addHours(3)->format('Y-m-d\TH:i') }}">3 hours</option><option value="{{ now()->addDay()->format('Y-m-d\TH:i') }}">1 day</option></select><input type="datetime-local" name="deadline_at" class="inp mt-1" value="{{ now()->addHour()->format('Y-m-d\TH:i') }}"></div>
    <div><label class="lbl">Funding</label><select name="funding" class="inp" x-model="f">@foreach($extra['fundings'] as $k=>$fd)<option value="{{ $k }}">{{ $fd[0] }}</option>@endforeach</select></div>
    <div><label class="lbl">Payment method</label><select name="payment_method_id" class="inp">@foreach($extra['methods'] as $m)<option value="{{ $m->id }}">{{ $m->name }}</option>@endforeach</select></div>
    <div x-show="f==='VENDOR_CREDIT'"><label class="lbl">Vendor</label><select name="vendor_id" class="inp"><option value="">—</option>@foreach($extra['vendors'] as $v)<option value="{{ $v->id }}">{{ $v->name }} ({{ $v->terms }})</option>@endforeach</select></div>
    <div x-show="f==='PETTY_CASH'"><label class="lbl">Petty cash fund</label><select name="petty_account_id" class="inp"><option value="">—</option>@foreach($extra['petty'] as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach</select></div>
    <div class="sm:col-span-3"><button class="btn btn-primary">Assign & notify buyer</button></div>
  </form>
  @endif

  @if($data['purchase'])
  @php($p = $data['purchase'])
  <div class="card p-4 text-sm">
    <div class="font-semibold mb-2">🛒 Purchase {{ $p['number'] }} {!! $U::badge($p['status']) !!} @if($p['price_flag'])<span class="badge bg-red-100 text-red-700">price above benchmark</span>@endif</div>
    <div>Buyer <b>{{ $p['buyer'] }}</b> · due {{ $p['deadline'] }} · {{ $p['funding_label'] }} @if($p['vendor'])· vendor {{ $p['vendor'] }}@endif @if($p['payment_method'])· {{ $p['payment_method'] }}@endif</div>
    @if($p['amount'])<div class="mt-1">Actual: <b>{{ $U::tk($p['amount']) }}</b></div>@endif
    @if($p['receipt_photo'])<a href="{{ $p['receipt_photo'] }}" target="_blank"><img src="{{ $p['receipt_photo'] }}" class="mt-2 h-40 rounded border"></a>@endif
  </div>
  @endif
</div>

<div class="space-y-4">
  @if(count($data['approvals']))
  <div class="card p-4"><div class="font-semibold mb-2">Approvals</div>
    @foreach($data['approvals'] as $s)<div class="text-sm py-1 border-b last:border-0">L{{ $s['level'] }} · {{ $s['approver'] }} {!! $U::badge($s['status']) !!}<div class="text-xs text-slate-500">{{ $s['by'] }} {{ $s['at'] }} {{ $s['comment'] ? '· “'.$s['comment'].'”' : '' }}</div></div>@endforeach
  </div>
  @endif
  @if(count($data['parents']) || count($data['children']))
  <div class="card p-4"><div class="font-semibold mb-2">🔗 Linked requests</div>
    @foreach($data['parents'] as $c)<a href="{{ route('req.show', $c['id']) }}" class="block text-sm py-1">↑ caused by <b>{{ $c['number'] }}</b> {{ $c['type_label'] }} {!! $U::badge($c['status']) !!}</a>@endforeach
    @foreach($data['children'] as $c)<a href="{{ route('req.show', $c['id']) }}" class="block text-sm py-1">↓ needs <b>{{ $c['number'] }}</b> {{ $c['type_label'] }} {!! $U::badge($c['status']) !!}</a>@endforeach
  </div>
  @endif
  @if($data['dispatch'])<div class="card p-4 text-sm"><div class="font-semibold mb-1">🚚 {{ $data['dispatch']['number'] }}</div>{{ $data['dispatch']['driver'] ?? 'internal' }} · {{ $data['dispatch']['vehicle'] }} {!! $U::badge($data['dispatch']['status']) !!}</div>@endif
  <div class="card p-4"><div class="font-semibold mb-2">Timeline</div>
    @foreach($data['history'] as $h)<div class="relative pl-4 pb-3 border-l-2 border-orange-200 text-sm"><span class="absolute -left-[5px] top-1 w-2 h-2 rounded-full bg-orange-500"></span>{!! $U::badge($h['status']) !!} <span class="text-xs text-slate-500">{{ $h['at'] }}</span><div class="text-xs">{{ $h['by'] }} {{ $h['note'] ? '· '.$h['note'] : '' }}</div></div>@endforeach
  </div>
</div>
</div>
@endsection
