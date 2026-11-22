<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BizException;
use App\Http\Controllers\Controller;
use App\Models\Dispatch;
use App\Models\ProductionOrder;
use App\Services\Notifier;
use App\Services\ProductionService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class OpsController extends Controller
{
    protected function dispatchJson(Dispatch $d): array
    {
        $d->loadMissing('requisition.lines.item', 'fromLocation', 'toLocation');

        return [
            'id' => $d->id, 'number' => $d->number, 'status' => $d->status, 'from' => $d->fromLocation->name, 'to' => $d->toLocation->name,
            'vehicle' => $d->vehicle, 'requisition' => $d->requisition->number, 'requisition_id' => $d->requisition_id,
            'items' => $d->requisition->lines->map(fn ($l) => ($l->item?->displayName() ?? $l->description).' '.(float) $l->qty_sent.' '.$l->item?->unit)->join(', '),
            'picked_up_at' => $d->picked_up_at?->format('h:i A'), 'delivered_at' => $d->delivered_at?->format('h:i A'),
            'created' => $d->created_at->format('d M, h:i A'),
        ];
    }

    public function deliveries(Request $r)
    {
        abort_unless($r->user()->hasPerm('dispatch.drive'), 403);
        $q = Dispatch::where('driver_id', $r->user()->id);
        $r->input('state') === 'done' ? $q->whereIn('status', ['DELIVERED', 'RECEIVED']) : $q->whereIn('status', ['READY', 'PICKED_UP']);

        return $q->latest('id')->limit(50)->get()->map(fn ($d) => $this->dispatchJson($d))->values();
    }

    public function pickup(Request $r, Dispatch $dispatch)
    {
        $this->own($r, $dispatch);
        if ($dispatch->status !== 'READY') {
            throw new BizException('Already picked up.');
        }
        $dispatch->update(['status' => 'PICKED_UP', 'picked_up_at' => now()]);

        return ['message' => 'Picked up', 'delivery' => $this->dispatchJson($dispatch)];
    }

    public function deliver(Request $r, Dispatch $dispatch)
    {
        $this->own($r, $dispatch);
        if (! in_array($dispatch->status, ['READY', 'PICKED_UP'])) {
            throw new BizException('Already delivered.');
        }
        $dispatch->update(['status' => 'DELIVERED', 'delivered_at' => now(), 'picked_up_at' => $dispatch->picked_up_at ?? now()]);
        $req = $dispatch->requisition;
        Notifier::send(Notifier::usersWithPerm('req.receive', $req->from_location_id)->pluck('id')->push($req->requester_id)->all(), 'Delivery arrived: '.$req->number, 'Please check and confirm received quantity', 'delivery', '/requisitions/'.$req->id);

        return ['message' => 'Delivered — receiver must confirm', 'delivery' => $this->dispatchJson($dispatch)];
    }

    protected function own(Request $r, Dispatch $d): void
    {
        abort_unless($d->driver_id === $r->user()->id || $r->user()->is_super_admin, 403);
    }

    public function production(Request $r)
    {
        abort_unless($r->user()->hasAnyPerm(['production.work', 'production.manage']), 403);
        $date = Carbon::parse($r->input('date', today()->toDateString()));

        return ProductionOrder::with('item', 'assignee')->whereDate('planned_for', $date)->where('location_id', $r->user()->location_id)
            ->orderBy('status')->get()->map(fn ($p) => [
                'id' => $p->id, 'number' => $p->number, 'item' => $p->item->displayName(), 'qty' => $p->qty, 'unit' => $p->item->unit,
                'status' => $p->status, 'assigned_to' => $p->assignee?->name, 'started_at' => $p->started_at?->format('h:i A'), 'completed_at' => $p->completed_at?->format('h:i A'),
            ]);
    }

    public function productionAction(Request $r, ProductionOrder $order, string $action)
    {
        $o = match ($action) {
            'start' => ProductionService::start($order, $r->user()),
            'complete' => ProductionService::complete($order, $r->user(), $r->filled('qty') ? (float) $r->qty : null),
            default => throw new BizException('Unknown action'),
        };

        return ['message' => $action === 'complete' ? 'Production done · stock updated' : 'Started', 'status' => $o->status];
    }
}
