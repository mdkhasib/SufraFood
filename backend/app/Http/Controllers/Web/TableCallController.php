<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\DiningTable;
use App\Models\Location;
use App\Models\TableCall;
use App\Services\Audit;
use App\Services\QrOrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Staff side of QR ordering: live table calls + QR bills, and the printable table QR codes. */
class TableCallController extends Controller
{
    protected function branchIds(Request $r): array
    {
        $u = $r->user();

        return $u->isGroupWide() ? Location::where('type', 'BRANCH')->pluck('id')->all() : [$u->location_id];
    }

    public function index(Request $r)
    {
        abort_unless(QrOrderService::on(), 403, 'QR table ordering is switched off in Company settings.');

        return view('qr.calls');
    }

    public function feed(Request $r)
    {
        abort_unless(QrOrderService::on(), 403);
        $ids = $r->filled('location') && in_array((int) $r->location, $this->branchIds($r)) ? [(int) $r->location] : $this->branchIds($r);

        return QrOrderService::feed($ids);
    }

    public function answer(Request $r, TableCall $call, string $what)
    {
        $c = QrOrderService::answer($call, $r->user(), $what);
        $msg = $what === 'coming' ? 'Table '.$c->table->name.' told: '.$r->user()->name.' is coming' : 'Done';

        return $r->expectsJson() ? ['message' => $msg] : back()->with('ok', $msg);
    }

    /** Printable QR codes for every table (and the branch), grouped by branch. */
    public function codes(Request $r)
    {
        abort_unless(QrOrderService::on(), 403, 'QR table ordering is switched off in Company settings.');
        $u = $r->user();
        $branches = Location::where('type', 'BRANCH')->where('active', true)->when(! $u->isGroupWide(), fn ($q) => $q->where('id', $u->location_id))->orderBy('name')->get();
        $loc = $r->integer('location') ?: $branches->first()?->id;
        abort_unless($branches->contains('id', $loc), 403);
        $tables = DiningTable::where('location_id', $loc)->orderBy('id')->get();

        return view('qr.codes', ['branches' => $branches, 'branch' => $branches->firstWhere('id', $loc), 'tables' => $tables]);
    }

    /** New QR code for a table (e.g. a photo of the old one is being misused). The old sticker stops working. */
    public function regenerate(Request $r, DiningTable $table)
    {
        $u = $r->user();
        abort_unless($u->isGroupWide() || $table->location_id === $u->location_id, 403);
        $table->update(['qr_token' => Str::lower(Str::random(12))]);
        Audit::log('qr.regenerated', $table);

        return back()->with('ok', 'New QR code for table '.$table->name.'. Print it and replace the old sticker.');
    }

    /** Branch manager: which waiter looks after which table (one waiter many tables, many waiters one table). */
    public function waiters(Request $r)
    {
        $u = $r->user();
        $branches = Location::where('type', 'BRANCH')->where('active', true)->when(! $u->isGroupWide(), fn ($q) => $q->where('id', $u->location_id))->orderBy('name')->get();
        $loc = $r->integer('location') ?: $branches->first()?->id;
        abort_unless($branches->contains('id', $loc), 403);
        $tables = DiningTable::with('waiters')->where('location_id', $loc)->orderBy('id')->get();
        $staff = \App\Services\TableWaiterService::candidates($loc);

        return view('qr.waiters', ['branches' => $branches, 'branch' => $branches->firstWhere('id', $loc), 'tables' => $tables, 'staff' => $staff]);
    }

    public function saveWaiters(Request $r)
    {
        $u = $r->user();
        $loc = $r->integer('location');
        abort_unless(Location::where('id', $loc)->where('type', 'BRANCH')->exists() && ($u->isGroupWide() || $u->location_id === $loc), 403);
        $n = \App\Services\TableWaiterService::save($loc, (array) $r->input('w', []), $u);
        Audit::log('tables.waiters', Location::find($loc), null, ['changed_tables' => $n]);

        return redirect()->route('tables.waiters', ['location' => $loc])->with('ok', $n ? $n.' table(s) updated. Their waiters now get the table calls as tasks.' : 'No changes.');
    }
}
