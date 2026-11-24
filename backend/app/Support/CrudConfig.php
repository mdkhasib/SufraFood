<?php

namespace App\Support;

use App\Models\Account;
use App\Models\Department;
use App\Models\Location;
use App\Models\PaymentMethod;
use App\Models\Role;
use App\Models\User;

/**
 * Master-data screens. field spec: [label, type, extra]
 * types: text, number, bool, select(options), date, time, password, textarea
 */
class CrudConfig
{
    public static function get(string $res): ?array
    {
        $loc = fn () => Location::orderBy('name')->pluck('name', 'id')->all();
        $all = [
            'users' => [
                'title' => 'Employees', 'model' => User::class, 'access' => ['hr.employees'], 'search' => ['name', 'employee_code', 'phone'],
                'with' => ['role', 'location', 'manager'], 'order' => 'employee_code',
                'list' => ['employee_code' => 'ID', 'name' => 'Name', 'role.name' => 'Role', 'location.name' => 'Location', 'manager.name' => 'Reports to', 'phone' => 'Phone', 'status' => 'Status'],
                'filter' => ['location_id' => ['Location', $loc]],
                'fields' => fn () => [
                    'employee_code' => ['Employee ID', 'text', 'required'], 'name' => ['Name (English)', 'text', 'required'], 'name_bn' => ['Name (বাংলা)', 'text'],
                    'phone' => ['Phone (login)', 'text', 'required'], 'email' => ['Email', 'text'], 'password' => ['Password (leave blank to keep)', 'password'],
                    'role_id' => ['Role', 'select', Role::orderBy('name')->pluck('name', 'id')->all()], 'location_id' => ['Location', 'select', Location::orderBy('name')->pluck('name', 'id')->all()],
                    'department_id' => ['Department', 'select', Department::orderBy('name')->pluck('name', 'id')->all()],
                    'manager_id' => ['Reports to', 'select', User::where('is_super_admin', false)->orderBy('name')->get()->mapWithKeys(fn ($u) => [$u->id => $u->name.' ('.$u->employee_code.')'])->all()],
                    'position' => ['Position', 'text'], 'can_deliver' => ['Can do home delivery (rider)', 'bool'], 'employment_type' => ['Employment type', 'select', ['PERMANENT' => 'Permanent', 'PROBATION' => 'Probation', 'PART_TIME' => 'Part-time', 'DAILY_WAGE' => 'Daily wage']],
                    'joined_at' => ['Joined', 'date'], 'status' => ['Status', 'select', ['ACTIVE' => 'Active', 'INACTIVE' => 'Inactive', 'LEFT' => 'Left']],
                    'basic_salary' => ['Basic salary ৳', 'number'], 'house_rent' => ['House rent ৳', 'number'], 'medical' => ['Medical ৳', 'number'], 'conveyance' => ['Conveyance ৳', 'number'],
                    'payout_method' => ['Salary paid by', 'select', ['CASH' => 'Cash', 'BANK' => 'Bank', 'MFS' => 'MFS (bKash/Nagad)']], 'payout_account' => ['Bank / MFS number', 'text'],
                    'approval_limit' => ['Approval limit ৳ (blank = no limit)', 'number'], 'advance_limit' => ['Cash advance limit ৳', 'number'],
                    'own_money_limit' => ['Own-money limit ৳', 'number'], 'discount_limit' => ['POS discount limit %', 'number'],
                    'language' => ['App language', 'select', ['en' => 'English', 'bn' => 'বাংলা']],
                ],
                'admin_fields' => ['is_company_admin'],
            ],
            'locations' => [
                'title' => 'Locations', 'model' => Location::class, 'access' => ['admin'], 'order' => 'id',
                'list' => ['code' => 'Code', 'name' => 'Name', 'type' => 'Type', 'address' => 'Area', 'phone' => 'Phone', 'lat' => 'Lat', 'lng' => 'Lng', 'radius_m' => 'Radius m'],
                'fields' => fn () => [
                    'code' => ['Code', 'text', 'required'], 'name' => ['Name', 'text', 'required'], 'name_bn' => ['Name (বাংলা)', 'text'],
                    'type' => ['Type', 'select', ['BRANCH' => 'Restaurant branch', 'CENTRAL_KITCHEN' => 'Central kitchen', 'WAREHOUSE' => 'Warehouse', 'CATERING' => 'Catering', 'OFFICE' => 'Office']],
                    'address' => ['Area / address (printed on bills)', 'text'], 'phone' => ['Branch phone (printed on bills)', 'text'], 'lat' => ['GPS latitude', 'number'], 'lng' => ['GPS longitude', 'number'], 'radius_m' => ['Check-in radius (m)', 'number'],
                    'manager_id' => ['Location head', 'select', User::where('is_super_admin', false)->orderBy('name')->pluck('name', 'id')->all()], 'active' => ['Active', 'bool'],
                ],
            ],
            'departments' => ['title' => 'Departments', 'model' => Department::class, 'access' => ['admin'], 'order' => 'name', 'list' => ['name' => 'Name', 'name_bn' => 'বাংলা'], 'fields' => fn () => ['name' => ['Name', 'text', 'required'], 'name_bn' => ['Name (বাংলা)', 'text']]],
            // sale items (POS / delivery menu) — always type MENU
            'menu_items' => [
                'title' => 'Sale items (menu)', 'model' => \App\Models\Item::class, 'access' => ['inventory.manage'], 'order' => 'category', 'search' => ['name', 'code', 'name_bn', 'category'],
                'scope' => fn ($q) => $q->whereIn('type', \App\Models\Item::SALE_TYPES), 'fixed' => ['type' => 'MENU'], 'defaults' => ['production_at' => 'BRANCH', 'buy_factor' => 1, 'unit' => 'plate'],
                'filter' => ['category' => ['Category', fn () => array_combine($c = \App\Models\Item::whereIn('type', \App\Models\Item::SALE_TYPES)->whereNotNull('category')->distinct()->orderBy('category')->pluck('category')->all(), $c)]],
                'list' => ['_image' => '', 'code' => 'Code', 'name' => 'Name', 'name_bn' => 'বাংলা', 'category' => 'Category', 'sale_price' => 'Sale ৳', 'production_at' => 'Cooked at', 'active' => 'Active'],
                'rules' => ['code' => 'unique:items,code,{id}', 'sale_price' => 'required|numeric|gt:0'],
                'fields' => fn () => [
                    'image' => ['Product photo (shown on the POS and delivery menu)', 'image'],
                    'code' => ['Code (e.g. MN-012)', 'text', 'required'], 'name' => ['Name', 'text', 'required'], 'name_bn' => ['Name (বাংলা)', 'text'],
                    'category' => ['Category', 'suggest', \App\Models\Item::categorySuggestions(true)],
                    'sale_price' => ['Sale price ৳', 'number', 'required'], 'unit' => ['Sold per (plate, pcs, glass…)', 'text', 'required'],
                    'production_at' => ['Cooked at', 'select', ['BRANCH' => 'Branch kitchen', 'CENTRAL' => 'Central kitchen', 'NONE' => 'Ready-made (no cooking)']], 'active' => ['On the menu (active)', 'bool'],
                ],
            ],
            // stock items: raw materials, central kitchen items, consumables, equipment — never sold on the POS
            'items' => [
                'title' => 'Raw materials & stock', 'model' => \App\Models\Item::class, 'access' => ['inventory.manage'], 'order' => 'code', 'search' => ['name', 'code', 'name_bn', 'category'],
                'scope' => fn ($q) => $q->whereIn('type', \App\Models\Item::STOCK_TYPES), 'defaults' => ['production_at' => 'NONE', 'buy_factor' => 1],
                'filter' => ['type' => ['Type', fn () => ['RAW' => 'Raw material', 'SEMI' => 'Semi (CK)', 'CONSUMABLE' => 'Consumable', 'ASSET' => 'Asset']],
                    'category' => ['Category', fn () => array_combine($c = \App\Models\Item::whereIn('type', \App\Models\Item::STOCK_TYPES)->whereNotNull('category')->distinct()->orderBy('category')->pluck('category')->all(), $c)]],
                'list' => ['_image' => '', 'code' => 'Code', 'name' => 'Name', 'name_bn' => 'বাংলা', 'type' => 'Type', 'category' => 'Category', 'unit' => 'Unit', 'last_price' => 'Cost ৳', 'reorder_level' => 'Reorder', 'production_at' => 'Cooked at'],
                'rules' => ['code' => 'unique:items,code,{id}', 'type' => 'required|in:RAW,SEMI,CONSUMABLE,ASSET'],
                'fields' => fn () => [
                    'image' => ['Photo (optional)', 'image'],
                    'code' => ['Code (e.g. RM-020)', 'text', 'required'], 'name' => ['Name', 'text', 'required'], 'name_bn' => ['Name (বাংলা)', 'text'],
                    'type' => ['Type', 'select', ['RAW' => 'Raw material', 'SEMI' => 'Semi-finished (central kitchen)', 'CONSUMABLE' => 'Consumable', 'ASSET' => 'Equipment / asset'], 'required'],
                    'category' => ['Category', 'suggest', \App\Models\Item::categorySuggestions(false)], 'unit' => ['Stock unit', 'text', 'required'], 'buy_unit' => ['Buying unit', 'text'], 'buy_factor' => ['Units per buying unit', 'number'],
                    'last_price' => ['Last cost ৳', 'number'], 'reorder_level' => ['Reorder level', 'number'],
                    'production_at' => ['Cooked at', 'select', ['NONE' => '—', 'CENTRAL' => 'Central kitchen', 'BRANCH' => 'Branch kitchen']], 'active' => ['Active', 'bool'],
                ],
            ],
            'vendors' => ['title' => 'Vendors', 'model' => \App\Models\Vendor::class, 'access' => ['purchase.manage'], 'order' => 'name', 'list' => ['name' => 'Name', 'phone' => 'Phone', 'items_supplied' => 'Supplies', 'terms' => 'Terms', 'credit_days' => 'Credit days'],
                'fields' => fn () => ['name' => ['Name', 'text', 'required'], 'phone' => ['Phone', 'text'], 'address' => ['Address', 'text'], 'items_supplied' => ['Items supplied', 'text'], 'terms' => ['Terms', 'select', ['CASH' => 'Cash', 'CREDIT' => 'Credit']], 'credit_days' => ['Credit days', 'number']]],
            'shift_templates' => ['title' => 'Shift templates', 'model' => \App\Models\ShiftTemplate::class, 'access' => ['shifts.manage'], 'order' => 'start_time', 'list' => ['code' => 'Code', 'name' => 'Name', 'start_time' => 'Start', 'end_time' => 'End', 'start_time2' => 'Start 2', 'end_time2' => 'End 2', 'break_minutes' => 'Break'],
                'fields' => fn () => ['code' => ['Code', 'text', 'required'], 'name' => ['Name', 'text', 'required'], 'start_time' => ['Start', 'time', 'required'], 'end_time' => ['End', 'time', 'required'], 'start_time2' => ['Split start (optional)', 'time'], 'end_time2' => ['Split end', 'time'], 'break_minutes' => ['Break minutes', 'number']]],
            'leave_types' => ['title' => 'Leave types', 'model' => \App\Models\LeaveType::class, 'access' => ['leave.manage'], 'order' => 'name', 'list' => ['name' => 'Name', 'name_bn' => 'বাংলা', 'days_per_year' => 'Days / year', 'paid' => 'Paid'],
                'fields' => fn () => ['name' => ['Name', 'text', 'required'], 'name_bn' => ['Name (বাংলা)', 'text'], 'days_per_year' => ['Days per year', 'number'], 'paid' => ['Paid', 'bool']]],
            'holidays' => ['title' => 'Holidays', 'model' => \App\Models\Holiday::class, 'access' => ['leave.manage'], 'order' => 'date', 'list' => ['date' => 'Date', 'name' => 'Name'], 'fields' => fn () => ['date' => ['Date', 'date', 'required'], 'name' => ['Name', 'text', 'required']]],
            'payment_methods' => ['title' => 'Payment methods', 'model' => PaymentMethod::class, 'access' => ['super'], 'order' => 'id', 'list' => ['name' => 'Name', 'type' => 'Type', 'active' => 'Active'],
                'fields' => fn () => ['name' => ['Name', 'text', 'required'], 'type' => ['Type', 'select', ['CASH' => 'Cash', 'BANK' => 'Bank', 'MFS' => 'MFS', 'CARD' => 'Card (POS)']], 'active' => ['Active', 'bool']]],
            'money_accounts' => ['title' => 'Money accounts', 'model' => \App\Models\MoneyAccount::class, 'access' => ['acc.manage'], 'order' => 'id', 'with' => ['location', 'custodian', 'paymentMethod'],
                'list' => ['name' => 'Name', 'type' => 'Type', 'paymentMethod.name' => 'Method', 'location.name' => 'Location', 'custodian.name' => 'Custodian', 'account_no' => 'A/C no', '_balance' => 'Balance ৳'],
                'fields' => fn () => ['name' => ['Name', 'text', 'required'], 'type' => ['Type', 'select', ['CASH' => 'Cash', 'BANK' => 'Bank', 'MFS' => 'MFS wallet', 'PETTY' => 'Petty cash', 'DRAWER' => 'POS drawer', 'CARD' => 'Card clearing']],
                    'payment_method_id' => ['Default for payment method', 'select', PaymentMethod::pluck('name', 'id')->all()], 'location_id' => ['Location', 'select', Location::pluck('name', 'id')->all()],
                    'custodian_id' => ['Custodian', 'select', User::where('is_super_admin', false)->orderBy('name')->pluck('name', 'id')->all()], 'account_no' => ['Account / wallet no', 'text'], 'active' => ['Active', 'bool']]],
            'dining_tables' => ['title' => 'Dining tables', 'model' => \App\Models\DiningTable::class, 'access' => ['admin'], 'order' => 'location_id', 'with' => ['location'], 'list' => ['location.name' => 'Branch', 'name' => 'Table', 'seats' => 'Seats'],
                'fields' => fn () => ['location_id' => ['Branch', 'select', Location::where('type', 'BRANCH')->pluck('name', 'id')->all()], 'name' => ['Name', 'text', 'required'], 'seats' => ['Seats', 'number']]],
        ];

        return $all[$res] ?? null;
    }

    public static function allowed(array $cfg, User $u): bool
    {
        if ($u->is_super_admin) {
            return true;
        }
        foreach ($cfg['access'] as $a) {
            if ($a === 'super') {
                continue;
            }
            if ($a === 'admin' && $u->is_company_admin) {
                return true;
            }
            if ($a !== 'admin' && ($u->hasPerm($a) || $u->is_company_admin)) {
                return true;
            }
        }

        return false;
    }
}
