<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Feature;
use App\Models\FeatureModule;
use App\Models\Location;
use App\Models\User;
use App\Services\Audit;
use App\Support\Features;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    public function features()
    {
        $features = Feature::with('locations')->orderBy('id')->get()->groupBy('module');
        $modules = FeatureModule::orderBy('sort')->get()->keyBy('name');
        // modules that exist only in the feature list still show (as ON)
        foreach ($features->keys() as $m) {
            $modules[$m] ??= new FeatureModule(['name' => $m, 'status' => 'ON', 'sort' => 99]);
        }
        $locations = Location::orderBy('id')->get();
        $counts = Feature::selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status');
        $moduleCounts = $modules->countBy('status');

        return view('super.features', compact('features', 'modules', 'locations', 'counts', 'moduleCounts'));
    }

    public function updateFeature(Request $r, Feature $feature)
    {
        $status = $r->input('status');
        abort_unless(in_array($status, ['OFF', 'PILOT', 'ON']), 422);
        if ($status !== 'OFF') {
            if (! Features::moduleOn($feature->module)) {
                return back()->with('error', 'Switch the “'.$feature->module.'” module ON first.');
            }
            foreach ($feature->depends_on ?? [] as $dep) {
                $d = Feature::where('key', $dep)->first();
                if ($d && ($d->status === 'OFF' || ! Features::moduleOn($d->module))) {
                    return back()->with('error', "Turn on “{$d->name}” first ({$d->module} module) — {$feature->name} depends on it.");
                }
            }
        } else {
            $dependents = Feature::where('status', '!=', 'OFF')->get()->filter(fn ($f) => in_array($feature->key, $f->depends_on ?? []));
            if ($dependents->count()) {
                return back()->with('error', 'Turn off first: '.$dependents->pluck('name')->join(', '));
            }
        }
        if ($status === 'PILOT' && ! $r->filled('locations')) {
            return back()->with('error', 'Choose the pilot locations.');
        }
        $before = ['status' => $feature->status, 'locations' => $feature->locations->pluck('code')->all()];
        $feature->update(['status' => $status]);
        $feature->locations()->sync($status === 'PILOT' ? $r->input('locations', []) : []);
        Features::flush();
        Audit::log('feature.'.strtolower($status), $feature, $before, ['status' => $status, 'locations' => $feature->fresh()->locations->pluck('code')->all()]);

        return back()->with('ok', $feature->name.' → '.$status);
    }

    public function bulk(Request $r)
    {
        $status = $r->input('status');
        abort_unless(in_array($status, ['ON', 'OFF']), 422);
        Feature::query()->update(['status' => $status]);
        FeatureModule::query()->update(['status' => $status]);
        \Illuminate\Support\Facades\DB::table('feature_locations')->delete();
        Features::flush();
        Audit::log('feature.bulk_'.strtolower($status));

        return back()->with('ok', 'All features '.$status);
    }

    /** Module master switch (ON/OFF) and, for Company settings, whether Company Admins may use it. */
    public function updateModule(Request $r, FeatureModule $module)
    {
        if ($r->has('admin_access')) {
            $module->update(['admin_access' => $r->boolean('admin_access')]);
            Features::flush();
            Audit::log('module.admin_access', $module, null, ['admin_access' => $module->admin_access]);

            return back()->with('ok', $module->name.': company admins '.($module->admin_access ? 'can now use it' : 'can no longer use it'));
        }
        $status = $r->input('status');
        abort_unless(in_array($status, ['ON', 'OFF']), 422);
        if ($status === 'OFF') {
            // features in other modules that still need something from this module
            $mine = Feature::where('module', $module->name)->pluck('key')->all();
            $blocking = Feature::where('module', '!=', $module->name)->where('status', '!=', 'OFF')->get()
                ->filter(fn ($f) => Features::moduleOn($f->module) && array_intersect($mine, $f->depends_on ?? []));
            if ($blocking->count()) {
                return back()->with('error', 'Turn off first (they need '.$module->name.'): '.$blocking->pluck('name')->join(', '));
            }
        }
        $module->update(['status' => $status]);
        if ($status === 'ON' && $r->boolean('with_features')) {
            Feature::where('module', $module->name)->where('status', 'OFF')->update(['status' => 'ON']);
        }
        Features::flush();
        Audit::log('module.'.strtolower($status), $module);

        return back()->with('ok', $module->name.' module → '.$status);
    }

    public function admins()
    {
        $users = User::with('role', 'location')->where('is_super_admin', false)->whereHas('role', fn ($q) => $q->whereIn('tier', ['T4', 'T3', 'OFFICE']))->orderByDesc('is_company_admin')->orderBy('name')->get();

        return view('super.admins', compact('users'));
    }

    public function toggleAdmin(Request $r, User $user)
    {
        $user->update(['is_company_admin' => ! $user->is_company_admin]);
        Audit::log($user->is_company_admin ? 'admin.granted' : 'admin.revoked', $user);

        return back()->with('ok', $user->name.($user->is_company_admin ? ' is now a Company Admin' : ' is no longer a Company Admin'));
    }

    public function audit(Request $r)
    {
        $logs = AuditLog::with('user')->when($r->filled('q'), fn ($q) => $q->where('action', 'like', '%'.$r->q.'%'))->latest('id')->paginate(60)->withQueryString();

        return view('super.audit', compact('logs'));
    }

    /** Sign-in IDs of the demo staff (moved off the public login page). */
    public function demo(Request $r)
    {
        $key = ['SA-0001' => 'Super Admin', 'E-1001' => 'MD / Owner', 'E-1002' => 'GM / Company Admin', 'E-1020' => 'HR Manager', 'E-1010' => 'Accounts Manager', 'E-1030' => 'Purchase Manager', 'E-1031' => 'Buyer', 'E-1040' => 'Warehouse Mgr', 'E-1050' => 'CK Head Chef', 'E-1060' => 'Driver', 'E-1070' => 'Catering Mgr', 'E-2001' => 'Branch Mgr Gulshan', 'E-2004' => 'Team Lead (proxy)', 'E-2008' => 'Worker', 'E-2011' => 'Cashier (POS)'];
        $users = \App\Models\User::with('role', 'location')->whereIn('employee_code', array_keys($key))->get();
        $q = trim((string) $r->input('q'));
        $list = \App\Models\User::with('role', 'location')
            ->when($q !== '', fn ($w) => $w->where(fn ($w) => $w->where('name', 'like', "%$q%")->orWhere('employee_code', 'like', "%$q%")->orWhere('phone', 'like', "%$q%")
                ->orWhereHas('role', fn ($x) => $x->where('name', 'like', "%$q%"))->orWhereHas('location', fn ($x) => $x->where('name', 'like', "%$q%"))))
            ->orderBy('location_id')->orderBy('employee_code')->get();

        return view('super.demo', compact('key', 'users', 'list'));
    }
}
