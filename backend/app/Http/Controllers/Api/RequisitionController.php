<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\BizException;
use App\Http\Controllers\Controller;
use App\Models\ApprovalStep;
use App\Models\Item;
use App\Models\Location;
use App\Models\Requisition;
use App\Models\Stock;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\RequisitionService;
use App\Support\P;
use Illuminate\Http\Request;

class RequisitionController extends Controller
{
    public function index(Request $r)
    {
        $u = $r->user();
        $q = RequisitionService::visibleTo($u)->with('lines.item', 'fromLocation', 'toLocation', 'requester');
        match ($r->input('box', 'mine')) {
            'mine' => $q->where(fn ($w) => $w->where('requester_id', $u->id)->orWhere('from_location_id', $u->location_id)),
            'incoming' => $q->where(fn ($w) => $w->where('to_location_id', $u->location_id)->orWhere(fn ($p) => $u->hasPerm('purchase.manage') ? $p->where('type', 'PURCHASE') : $p->whereRaw('1=0')))->whereNotIn('status', ['DRAFT', 'PENDING_APPROVAL']),
            default => null,
        };
        if ($r->input('state') === 'open') {
            $q->whereNotIn('status', ['CLOSED', 'CANCELLED', 'REJECTED']);
        } elseif ($r->input('state') === 'closed') {
            $q->whereIn('status', ['CLOSED', 'CANCELLED', 'REJECTED']);
        }

        return $q->latest('id')->limit(100)->get()->map(fn ($x) => P::requisitionBrief($x))->values();
    }

    public function types(Request $r)
    {
        $u = $r->user();
        $types = [];
        foreach (config('groupops.requisition_types') as $key => $t) {
            if (RequisitionService::canCreate($u, $key)) {
                $itemTypes = match ($key) {
                    'FOOD_ORDER' => ['SEMI'],
                    'RAW_MATERIAL' => ['RAW'],
                    'SUPPLIES' => ['CONSUMABLE', 'ASSET'],
                    'EQUIPMENT' => ['ASSET', 'CONSUMABLE'],
                    'REPAIR' => [],
                    'PURCHASE' => ['RAW', 'CONSUMABLE', 'ASSET'],
                    'TRANSFER' => ['RAW', 'SEMI', 'CONSUMABLE'],
                };
                $types[] = ['key' => $key, 'label' => $t[0], 'item_types' => $itemTypes, 'free_text' => $key === 'REPAIR'];
            }
        }

        return [
            'types' => $types,
            'branches' => Location::where('type', 'BRANCH')->where('id', '!=', $u->location_id)->get(['id', 'name']),
            'cutoff' => \App\Support\Features::setting('orders.cutoff_time', '20:00'),
        ];
    }

    public function items(Request $r)
    {
        $q = Item::where('active', true)->orderBy('name');
        if ($r->filled('types')) {
            $q->whereIn('type', explode(',', $r->types));
        }
        if ($r->filled('q')) {
            $q->where(fn ($w) => $w->where('name', 'like', '%'.$r->q.'%')->orWhere('name_bn', 'like', '%'.$r->q.'%')->orWhere('code', 'like', '%'.$r->q.'%'));
        }

        return $q->limit(200)->get(['id', 'code', 'name', 'name_bn', 'type', 'unit', 'last_price', 'sale_price']);
    }

    public function store(Request $r)
    {
        $r->validate(['type' => 'required|string', 'lines' => 'required|array|min:1']);
        $req = RequisitionService::create($r->user(), $r->only('type', 'lines', 'needed_by', 'priority', 'notes', 'to_location_id', 'from_location_id'));

        return ['message' => $req->number.' sent', 'requisition' => P::requisition($req, $r->user())];
    }

    public function show(Request $r, Requisition $requisition)
    {
        $this->authorizeView($r->user(), $requisition);

        return P::requisition($requisition, $r->user());
    }

    protected function authorizeView(User $u, Requisition $req): void
    {
        if (! RequisitionService::visibleTo($u)->whereKey($req->id)->exists()
            && ! ApprovalStep::where('approvable_type', $req->getMorphClass())->where('approvable_id', $req->id)->get()->contains(fn ($s) => ApprovalService::approversFor($s)->contains('id', $u->id))) {
            abort(403, 'You cannot see this request.');
        }
    }

    public function action(Request $r, Requisition $requisition, string $action)
    {
        $u = $r->user();
        $qtys = collect($r->input('qtys', []))->mapWithKeys(fn ($v, $k) => [(int) $k => (float) $v])->all();
        $result = match ($action) {
            'submit' => tap($requisition, fn ($x) => RequisitionService::submit($x, $u)),
            'update-draft' => RequisitionService::updateDraftLines($requisition, $u, $qtys),
            'accept' => RequisitionService::accept($requisition, $u, $qtys, $r->input('note')),
            'reject' => RequisitionService::rejectByFulfiller($requisition, $u, (string) $r->input('reason')),
            'shortage' => RequisitionService::raiseShortagePurchase($requisition, $u),
            'dispatch' => RequisitionService::dispatch($requisition, $u, $qtys, $r->input('driver_id') ? (int) $r->input('driver_id') : null, $r->input('vehicle')),
            'receive' => RequisitionService::receive($requisition, $u, $qtys, $r->input('note')),
            'complete' => RequisitionService::complete($requisition, $u, $r->input('note')),
            'cancel' => RequisitionService::cancel($requisition, $u, $r->input('reason')),
            default => throw new BizException('Unknown action.'),
        };
        $msg = $action === 'shortage' ? 'Purchase request '.$result->number.' raised' : 'Done';

        return ['message' => $msg, 'requisition' => P::requisition($requisition->fresh(), $u)];
    }

    public function drivers()
    {
        return User::whereHas('role', fn ($q) => $q->where('key', 'driver'))->where('status', 'ACTIVE')->get(['id', 'name', 'phone']);
    }

    public function stock(Request $r)
    {
        abort_unless($r->user()->hasPerm('inventory.view'), 403);
        $loc = $r->user()->isGroupWide() && $r->filled('location_id') ? (int) $r->location_id : $r->user()->location_id;

        return Stock::with('item')->where('location_id', $loc)->whereHas('item', fn ($q) => $r->filled('q') ? $q->where('name', 'like', '%'.$r->q.'%') : $q)->get()
            ->sortBy(fn ($s) => $s->item->name)->map(fn ($s) => ['item' => $s->item->displayName(), 'code' => $s->item->code, 'unit' => $s->item->unit, 'qty' => $s->qty, 'reorder' => $s->item->reorder_level, 'low' => $s->item->reorder_level > 0 && $s->qty < $s->item->reorder_level])->values();
    }

    // ---------- approvals ----------
    public function approvals(Request $r)
    {
        return ApprovalService::inbox($r->user())->map(function (ApprovalStep $s) {
            $m = $s->approvable;
            $requester = $m->requester ?? $m->user ?? null;

            return [
                'id' => $s->id, 'level' => $s->level, 'type' => ApprovalService::typeOf($m), 'title' => ApprovalService::titleOf($m),
                'amount' => ApprovalService::amountOf($m), 'requester' => $requester?->name, 'since' => $s->updated_at->diffForHumans(),
                'overdue' => $s->due_at && $s->due_at->isPast(),
                'requisition_id' => $m instanceof Requisition ? $m->id : null,
                'details' => $m instanceof Requisition ? P::requisitionBrief($m)['items'] : ($m->reason ?? $m->description ?? null),
            ];
        })->values();
    }

    public function act(Request $r, ApprovalStep $step)
    {
        ApprovalService::act($step, $r->user(), $r->boolean('approve'), $r->input('comment'));

        return ['message' => $r->boolean('approve') ? 'Approved' : 'Rejected'];
    }
}
