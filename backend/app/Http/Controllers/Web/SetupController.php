<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\ApprovalRule;
use App\Models\Delegation;
use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\Audit;
use App\Support\Features;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SetupController extends Controller
{
    /** Company settings, grouped. [label, type, options|'super'] — 'super' rows only for the Super Admin. */
    public const SECTIONS = [
        'Company profile' => [
            'company.name' => ['Company name', 'text'],
            'company.name_bn' => ['Company name (বাংলা)', 'text'],
            'company.address' => ['Address', 'text'],
            'company.phone' => ['Phone', 'text'],
            'company.vat_reg' => ['VAT / BIN number', 'text'],
        ],
        'Logo & bill (POS)' => [
            'company.logo' => ['Company logo (PNG/JPG, shown on bills, web and app)', 'image'],
            'company.tagline' => ['Tagline under the name', 'text'],
            'receipt.title' => ['Bill title (e.g. INVOICE, BILL, ক্যাশ মেমো)', 'text'],
            'receipt.paper' => ['Printer paper width', 'select', ['80' => '80 mm (standard thermal)', '58' => '58 mm (small thermal)', 'A5' => 'A5 / A4 normal printer']],
            'receipt.font_size' => ['Font size (px)', 'number'],
            'receipt.copies' => ['Copies to print', 'select', ['1' => '1 · customer copy', '2' => '2 · customer + office copy']],
            'receipt.show_logo' => ['Print the logo', 'bool'],
            'receipt.show_address' => ['Print branch address', 'bool'],
            'receipt.show_phone' => ['Print branch phone', 'bool'],
            'receipt.show_vat_reg' => ['Print VAT / BIN number', 'bool'],
            'receipt.show_bn_names' => ['Print Bangla item names too', 'bool'],
            'receipt.show_cashier' => ['Print cashier name', 'bool'],
            'receipt.show_customer' => ['Print customer phone', 'bool'],
            'receipt.auto_print' => ['Open the print window automatically after payment', 'bool'],
            'receipt.footer' => ['Footer line 1', 'text'],
            'receipt.footer2' => ['Footer line 2 (small)', 'text'],
        ],
        'QR table ordering' => [
            'qr.enabled' => ['QR menu is ON (each table has a QR code customers scan)', 'bool'],
            'qr.allow_order' => ['Customers can order from the QR menu (goes straight to kitchen + POS)', 'bool'],
            'qr.allow_calls' => ['Customers can call a waiter / ask for the bill from the QR menu', 'bool'],
            'qr.branch_qr' => ['Branch QR code too (customer picks the table number)', 'bool'],
            'qr.ask_phone' => ['Ask the customer for a phone number before ordering', 'bool'],
            'qr.max_qty' => ['Max portions in one QR order (stops prank orders)', 'number'],
            'qr.welcome' => ['Welcome line on the QR menu', 'text'],
        ],
        'Home delivery' => [
            'delivery.enabled' => ['Home delivery is ON (orders, delivery board, rider tasks)', 'bool'],
            'delivery.rate_default' => ['Rider bonus per delivery ৳ (default; set each branch below)', 'number'],
            'delivery.target_minutes' => ['Deliver within (minutes) — rider task due time', 'number'],
            'delivery.need_kitchen_ready' => ['Rider can pick up only after the kitchen marks it ready', 'bool'],
            'delivery.riders' => ['Who can be given a delivery', 'select', ['MARKED' => 'Staff ticked “Can do home delivery” (waiters, riders)', 'ALL' => 'Anyone working at that branch']],
            'delivery.bonus_in_payroll' => ['Add the delivery bonus to the rider’s salary (payroll)', 'bool'],
        ],
        'Mobile app' => [
            'app.server_url' => ['Server address the app should use (leave empty = this server’s address)', 'text'],
        ],
        'Tasks & reminders' => [
            'tasks.assign_list' => ['Who shows in the “Assign task” list', 'select', ['LEADERS' => 'Direct reports + team leads/managers (no front-line crowd)', 'ALL' => 'Everyone below me']],
            'tasks.default_remind_before' => ['Default reminder before due (minutes)', 'number'],
            'tasks.high_remind_every' => ['High priority: remind every (minutes) when late', 'number'],
            'tasks.urgent_remind_every' => ['Top priority: remind every (minutes) when late', 'number'],
            'tasks.max_reminders' => ['Max automatic reminders per task', 'number'],
        ],
        'Attendance' => [
            'attendance.grace_minutes' => ['Late grace minutes', 'number'],
            'attendance.max_accuracy_m' => ['Max GPS inaccuracy allowed (m)', 'number'],
            'attendance.arrival_alert_mode' => ['Arrival alert to team lead', 'select', ['EVERY' => 'Every check-in', 'LATE_ONLY' => 'Only late / proxy / remote']],
            'attendance.proxy_max' => ['Max people per proxy', 'number'],
            'attendance.lates_per_deduction' => ['Lates that equal 1 day deduction', 'number'],
            'attendance.device_binding' => ['One registered phone per employee', 'bool'],
            'attendance.test_mode' => ['TEST MODE: allow check-in outside the GPS area (super admin only)', 'bool', 'super'],
        ],
        'Salary advance' => [
            'advance.max_percent' => ['Max advance as % of basic salary', 'number'],
            'advance.max_months' => ['Max months to pay it back', 'number'],
            'advance.min_service_months' => ['Must have worked at least (months)', 'number'],
            'advance.one_open_only' => ['Only one unpaid advance at a time', 'bool'],
        ],
        'Payroll & KPI' => [
            'payroll.working_days' => ['Working days per month (day rate)', 'number'],
            'payroll.ot_multiplier' => ['Overtime multiplier', 'number'],
            'payroll.kpi_band_high' => ['KPI high band (score ≥)', 'number'], 'payroll.kpi_band_high_amount' => ['KPI high band incentive ৳', 'number'],
            'payroll.kpi_band_mid' => ['KPI mid band (score ≥)', 'number'], 'payroll.kpi_band_mid_amount' => ['KPI mid band incentive ৳', 'number'],
            'kpi.w_ontime' => ['KPI weight: task on time %', 'number'], 'kpi.w_completion' => ['KPI weight: task completion %', 'number'],
            'kpi.w_rework' => ['KPI weight: no rework %', 'number'], 'kpi.w_attendance' => ['KPI weight: attendance & punctuality %', 'number'],
        ],
        'Orders, POS & purchase' => [
            'pos.offline_enabled' => ['POS keeps selling when the network / server is down (offline bills sync later)', 'bool'],
            'pos.sync_minutes' => ['Sync offline POS bills every (minutes)', 'number'],
            'orders.cutoff_time' => ['Food order cut-off time (next day)', 'time'],
            'pos.vat_percent' => ['POS VAT %', 'number'], 'pos.service_charge_percent' => ['POS service charge %', 'number'],
            'purchase.price_flag_percent' => ['Flag purchase price above last price by %', 'number'],
        ],
    ];

    public static function labels(): array
    {
        return array_merge(...array_values(self::SECTIONS));
    }

    public function settings(Request $r)
    {
        abort_unless(Features::canCompanySettings($r->user()), 403, 'Company settings are managed by the Super Admin.');

        return view('setup.settings', ['sections' => self::SECTIONS]);
    }

    public function saveSettings(Request $r)
    {
        abort_unless(Features::canCompanySettings($r->user()), 403, 'Company settings are managed by the Super Admin.');
        foreach (self::labels() as $k => $def) {
            if (($def[2] ?? null) === 'super' && ! $r->user()->is_super_admin) {
                continue;
            }
            $field = str_replace('.', '__', $k);
            if ($def[1] === 'image') {
                if ($r->boolean($field.'__remove')) {
                    $val = '';
                } elseif ($r->hasFile($field)) {
                    $r->validate([$field => 'image|mimes:png,jpg,jpeg,webp|max:2048']);
                    $val = $r->file($field)->store('branding', 'public');
                } else {
                    continue;
                }
            } elseif ($def[1] === 'bool') {
                if (! $r->has($field.'__present')) {
                    continue;
                }
                $val = $r->boolean($field) ? 1 : 0;
            } else {
                $val = $r->input($field);
            }
            if ($val === null) {
                continue;
            }
            $old = Features::setting($k);
            if ((string) $old !== (string) $val) {
                Features::setSetting($k, $val);
                Audit::log('setting.changed', null, [$k => $old], [$k => $val]);
            }
        }

        // per-branch rider rate (Home delivery)
        foreach ((array) $r->input('branch_rate', []) as $id => $rate) {
            $l = Location::where('type', 'BRANCH')->find((int) $id);
            $new = ($rate === null || $rate === '') ? null : max(0, (float) $rate);
            if ($l && $l->delivery_rate !== $new) {
                Audit::log('setting.delivery_rate', $l, ['delivery_rate' => $l->delivery_rate], ['delivery_rate' => $new]);
                $l->update(['delivery_rate' => $new]);
            }
        }

        return back()->with('ok', 'Settings saved');
    }

    /** Bill preview for the "Logo & bill" settings: the latest paid POS bill, or a sample one when there is none yet. */
    public function billPreview(Request $r)
    {
        abort_unless(Features::canCompanySettings($r->user()), 403);
        $o = \App\Models\PosOrder::with('lines.item', 'payments.paymentMethod', 'location', 'cashier', 'table')->where('status', 'PAID')->latest('id')->first();
        if (! $o) {
            $o = new \App\Models\PosOrder(['number' => 'GUL-000123', 'order_type' => 'DINE_IN', 'status' => 'PAID', 'customer_phone' => '01711-000000', 'subtotal' => 870, 'discount' => 0, 'service_charge' => 0, 'vat' => 43.5, 'total' => 913.5]);
            $o->paid_at = now();
            $loc = Location::where('type', 'BRANCH')->first() ?? new Location(['name' => 'Branch']);
            $items = \App\Models\Item::where('type', 'MENU')->limit(3)->get();
            $lines = $items->map(fn ($i) => (new \App\Models\PosOrderLine(['qty' => 1, 'price' => $i->sale_price, 'total' => $i->sale_price]))->setRelation('item', $i));
            $o->setRelation('lines', $lines)->setRelation('location', $loc)->setRelation('cashier', $r->user())->setRelation('table', null)
                ->setRelation('payments', collect([(new \App\Models\PosPayment(['amount' => 913.5]))->setRelation('paymentMethod', new \App\Models\PaymentMethod(['name' => 'Cash']))]));
        }

        return view('pos.receipt', ['o' => $o, 'preview' => true, 'tendered' => (float) $o->total < 1000 ? 1000 : 0]);
    }

    public function roles()
    {
        $roles = Role::withCount('users')->orderBy('id')->get();
        $perms = collect(config('groupops.permissions'))->map(fn ($p, $k) => ['key' => $k, 'label' => $p[0], 'feature' => $p[1], 'group' => $p[2], 'on' => Features::enabled($p[1], null, true)])->groupBy('group');
        $matrix = DB::table('role_permissions')->get()->groupBy('role_id')->map(fn ($g) => $g->pluck('permission')->all());

        return view('setup.roles', compact('roles', 'perms', 'matrix'));
    }

    public function saveRoles(Request $r)
    {
        $grid = $r->input('perm', []);
        $valid = array_keys(config('groupops.permissions'));
        $changed = 0;
        foreach (Role::all() as $role) {
            $new = array_values(array_intersect($grid[$role->id] ?? [], $valid));
            $old = $role->permissionKeys();
            sort($new);
            sort($old);
            if ($new != $old) {
                DB::table('role_permissions')->where('role_id', $role->id)->delete();
                DB::table('role_permissions')->insert(array_map(fn ($p) => ['role_id' => $role->id, 'permission' => $p], $new));
                Audit::log('role.permissions_changed', $role, ['removed' => array_values(array_diff($old, $new))], ['added' => array_values(array_diff($new, $old))]);
                $changed++;
            }
        }

        return redirect()->route('setup.roles', ['tab' => 'edit'])->with('ok', $changed ? "Permissions saved ($changed role(s) changed)" : 'No changes');
    }

    public function updateRole(Request $r, Role $role)
    {
        $r->validate(['name' => 'required|max:80', 'tier' => 'required|in:T1,T2,T3,T4,FUNCTION,OFFICE', 'scope' => 'required|in:SELF,TEAM,LOCATION,ALL']);
        $before = $role->only('name', 'tier', 'scope');
        $role->update($r->only('name', 'tier', 'scope'));
        Audit::log('role.updated', $role, $before, $role->only('name', 'tier', 'scope'));

        return back()->with('ok', 'Role updated');
    }

    public function deleteRole(Role $role)
    {
        if ($role->users()->exists()) {
            return back()->with('error', 'Move the people in “'.$role->name.'” to another role first.');
        }
        DB::table('role_permissions')->where('role_id', $role->id)->delete();
        Audit::log('role.deleted', $role, $role->toArray());
        $role->delete();

        return back()->with('ok', 'Role deleted');
    }
    public function newRole(Request $r)
    {
        $r->validate(['key' => 'required|alpha_dash|unique:roles,key', 'name' => 'required']);
        $role = Role::create($r->only('key', 'name', 'tier', 'scope'));
        DB::table('role_permissions')->insert(array_map(fn ($p) => ['role_id' => $role->id, 'permission' => $p], config('groupops.self_permissions')));
        Audit::log('role.created', $role);

        return back()->with('ok', 'Role created');
    }

    public function rules()
    {
        return view('setup.rules', [
            'rules' => ApprovalRule::with('location')->orderBy('request_type')->orderBy('min_amount')->get(),
            'types' => ApprovalService::TYPES, 'roles' => Role::orderBy('name')->pluck('name', 'key'),
            'users' => User::where('is_super_admin', false)->orderBy('name')->pluck('name', 'id'), 'locations' => Location::pluck('name', 'id'),
        ]);
    }

    public function saveRule(Request $r)
    {
        $r->validate(['request_type' => 'required', 'min_amount' => 'nullable|numeric', 'max_amount' => 'nullable|numeric']);
        $levels = [];
        foreach ($r->input('levels', []) as $lv) {
            if (! empty($lv['by'])) {
                $levels[] = ['by' => $lv['by'], 'value' => $lv['by'] === 'role' ? ($lv['role'] ?? null) : ($lv['by'] === 'user' ? ($lv['user'] ?? null) : null)];
            }
        }
        $data = ['request_type' => $r->request_type, 'location_id' => $r->location_id ?: null, 'min_amount' => $r->min_amount ?: 0, 'max_amount' => $r->max_amount ?: null, 'levels' => $levels, 'sla_minutes' => $r->sla_minutes ?: null];
        $rule = $r->filled('id') ? tap(ApprovalRule::findOrFail($r->id))->update($data) : ApprovalRule::create($data);
        Audit::log('approval_rule.saved', $rule, null, $data);

        return back()->with('ok', 'Approval rule saved');
    }

    public function deleteRule(ApprovalRule $rule)
    {
        Audit::log('approval_rule.deleted', $rule, $rule->toArray());
        $rule->delete();

        return back()->with('ok', 'Rule deleted');
    }

    public function delegations()
    {
        return view('setup.delegations', ['items' => Delegation::with('fromUser', 'toUser')->latest('id')->get(), 'users' => User::where('is_super_admin', false)->orderBy('name')->pluck('name', 'id')]);
    }

    public function saveDelegation(Request $r)
    {
        $r->validate(['from_user_id' => 'required|different:to_user_id', 'to_user_id' => 'required', 'from_date' => 'required|date', 'to_date' => 'required|date|after_or_equal:from_date']);
        $d = Delegation::create($r->only('from_user_id', 'to_user_id', 'from_date', 'to_date'));
        Audit::log('delegation.created', $d);

        return back()->with('ok', 'Delegation saved');
    }

    public function deleteDelegation(Delegation $delegation)
    {
        $delegation->delete();

        return back()->with('ok', 'Removed');
    }

    public function geofence(Request $r, Location $location)
    {
        $r->validate(['lat' => 'required|numeric', 'lng' => 'required|numeric']);
        $location->update(['lat' => $r->lat, 'lng' => $r->lng, 'radius_m' => $r->radius_m ?: $location->radius_m]);
        Audit::log('location.geofence', $location);

        return back()->with('ok', 'GPS area updated');
    }
}
