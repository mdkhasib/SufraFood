<?php

namespace App\Http\Controllers\Web;

use App\Exceptions\BizException;
use App\Http\Controllers\Controller;
use App\Models\ApprovalStep;
use App\Models\Item;
use App\Models\Location;
use App\Models\MoneyAccount;
use App\Models\PaymentMethod;
use App\Models\Requisition;
use App\Models\StandingOrder;
use App\Models\User;
use App\Models\Vendor;
use App\Services\ApprovalService;
use App\Services\PurchaseService;
use App\Services\RequisitionService;
use Illuminate\Http\Request;

class RequisitionController extends Controller
{
    public function index(Request $r)
    {
        $u = $r->user();
        $box = $r->input('box', 'all');
        $q = RequisitionService::visibleTo($u)->with('lines.item', 'fromLocation', 'toLocation', 'requester');
        match ($box) {
            'mine' => $q->where(fn ($w) => $w->where('requester_id', $u->id)->orWhere('from_location_id', $u->location_id)),
            'incoming' => $q->where('to_location_id', $u->location_id)->whereNotIn('status', ['DRAFT', 'PENDING_APPROVAL']),
            'open' => $q->whereNotIn('status', ['CLOSED', 'CANCELLED', 'REJECTED']),
            default => null,
        };
        if ($r->filled('type')) {
            $q->where('type', $r->type);
        }
        $items = $q->latest('id')->paginate(40)->withQueryString();
        $canCreate = collect(config('groupops.requisition_types'))->keys()->contains(fn ($t) => RequisitionService::canCreate($u, $t));

        return view('req.index', compact('items', 'box', 'canCreate'));
    }

    public function create(Request $r)
    {
        $u = $r->user();
        $types = collect(config('groupops.requisition_types'))->filter(fn ($t, $k) => RequisitionService::canCreate($u, $k));
        abort_if($types->isEmpty(), 403, 'You cannot raise requests.');
        $items = Item::where('active', true)->whereIn('type', ['RAW', 'SEMI', 'CONSUMABLE', 'ASSET'])->orderBy('name')->get(['id', 'code', 'name', 'name_bn', 'type', 'unit', 'last_price']);
        $branches = Location::where('type', 'BRANCH')->where('id', '!=', $u->location_id)->get();
        $locations = $u->isGroupWide() ? Location::orderBy('name')->get() : collect();

        return view('req.create', compact('types', 'items', 'branches', 'locations'));
    }

    public function store(Request $r)
    {
        $lines = collect($r->input('lines', []))->filter(fn ($l) => ! empty($l['qty']))->values()->all();
        $req = RequisitionService::create($r->user(), ['lines' => $lines] + $r->only('type', 'needed_by', 'priority', 'notes', 'to_location_id', 'from_location_id'));

        return redirect()->route('req.show', $req)->with('ok', $req->number.' sent');
    }

    public function show(Request $r, Requisition $requisition)
    {
        $u = $r->user();
        $steps = ApprovalService::stepsFor($requisition);
        $visible = RequisitionService::visibleTo($u)->whereKey($requisition->id)->exists() || $steps->contains(fn ($s) => ApprovalService::approversFor($s)->contains('id', $u->id));
        abort_unless($visible, 403);
        $data = \App\Support\P::requisition($requisition, $u);
        $req = $requisition->fresh(['lines.item', 'purchaseAssignment.buyer', 'purchaseAssignment.vendor']);
        $myStep = $steps->first(fn ($s) => ApprovalService::canAct($s, $u));
        $extra = [];
        if (in_array('assign', $data['actions'])) {
            $extra = [
                'buyers' => User::whereHas('role', fn ($q) => $q->where('key', 'buyer'))->get()->map(fn ($b) => ['id' => $b->id, 'name' => $b->name, 'wallet' => PurchaseService::walletBalance($b)]),
                'vendors' => Vendor::orderBy('name')->get(), 'methods' => PaymentMethod::where('active', true)->where('type', '!=', 'CARD')->get(),
                'petty' => MoneyAccount::where('type', 'PETTY')->get(),
                'fundings' => collect(PurchaseService::FUNDING)->filter(fn ($f) => $u->featureOn($f[1])),
            ];
        }
        if (in_array('dispatch', $data['actions'])) {
            $extra['drivers'] = User::whereHas('role', fn ($q) => $q->where('key', 'driver'))->get();
        }

        return view('req.show', compact('data', 'req', 'myStep', 'extra'));
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
            'assign' => PurchaseService::assign($requisition, $u, $r->only('buyer_id', 'deadline_at', 'funding', 'payment_method_id', 'vendor_id', 'petty_account_id')),
            default => throw new BizException('Unknown action'),
        };
        if ($action === 'shortage') {
            return redirect()->route('req.show', $result)->with('ok', 'Purchase request '.$result->number.' raised for the shortage');
        }

        return back()->with('ok', 'Done: '.$action);
    }

    public function standing(Request $r)
    {
        $u = $r->user();
        $locId = $u->isGroupWide() ? $r->input('location_id', Location::where('code', 'BR-GUL')->value('id')) : $u->location_id;
        $rows = StandingOrder::where('location_id', $locId)->get()->groupBy('item_id');
        $items = Item::where('type', 'SEMI')->where('active', true)->orderBy('name')->get();
        $locations = $u->isGroupWide() ? Location::where('type', 'BRANCH')->get() : collect();
        $drafts = Requisition::where('source', 'STANDING')->where('from_location_id', $locId)->where('status', 'DRAFT')->with('lines.item')->get();

        return view('req.standing', compact('rows', 'items', 'locations', 'locId', 'drafts'));
    }

    public function saveStanding(Request $r)
    {
        $u = $r->user();
        $locId = $u->isGroupWide() ? (int) $r->location_id : $u->location_id;
        foreach ($r->input('qty', []) as $itemId => $days) {
            foreach ($days as $wd => $q) {
                StandingOrder::updateOrCreate(['location_id' => $locId, 'item_id' => $itemId, 'weekday' => $wd], ['qty' => (float) $q, 'active' => true]);
            }
        }
        if ($r->boolean('generate_now')) {
            RequisitionService::generateStanding();
        }

        return back()->with('ok', 'Fixed orders saved');
    }
}
