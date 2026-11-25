<?php

namespace App\Support;

use App\Models\User;

/** Sidebar built only from what this person can use (feature + role gates). */
class Menu
{
    public static function for(User $u): array
    {
        $p = fn (string ...$perms) => $u->hasAnyPerm($perms);
        $admin = $u->is_super_admin || $u->is_company_admin;
        // every item is tied to a feature: when the Super Admin switches a feature (or its module) OFF, the item disappears for everyone
        $on = fn (string ...$keys) => collect($keys)->contains(fn ($k) => $u->featureOn($k));
        $reqAny = $p('req.create.food_order', 'req.create.raw_material', 'req.create.supplies', 'req.create.equipment', 'req.create.repair', 'req.create.purchase', 'req.create.transfer', 'req.fulfil', 'req.receive') || ($u->isGroupWide() && $u->featureOn('inventory.stock'));
        $groups = [
            'Main' => [
                ['Dashboard', 'home', 'home', true],
                ['Approvals', 'approvals', 'check', true],
                ['Notifications', 'notifications', 'bell', true],
                ['My tasks', 'tasks', 'list', $p('tasks.view_own', 'tasks.view_team')],
                ['My attendance', 'attendance.me', 'clock', $p('attendance.self')],
            ],
            'Team & Org' => [
                ['Org chart', 'org', 'users', $p('teams.view')],
                ['Teams', 'teams', 'grid', $p('teams.view')],
            ],
            'Operations' => [
                ['Requisitions', 'req.index', 'inbox', $reqAny],
                ['Fixed orders', 'req.standing', 'repeat', $p('req.standing')],
                ['Stock', 'inv.stock', 'box', $p('inventory.view')],
                ['Recipes', 'inv.recipes', 'book', $p('production.manage') || ($p('inventory.manage') && $u->featureOn('production.plan'))],
                ['Production', 'prod.index', 'fire', $p('production.manage', 'production.work')],
                ['Deliveries', 'deliveries', 'truck', $p('dispatch.drive') || ($p('req.fulfil') && $u->featureOn('dispatch.delivery'))],
            ],
            'Purchase' => [
                ['Purchase board', 'pur.index', 'cart', $p('purchase.manage', 'purchase.buy')],
                ['Buyer wallets', 'pur.wallets', 'wallet', $p('purchase.advance', 'purchase.manage', 'purchase.buy')],
                ['Refund claims', 'pur.claims', 'receipt', ($p('purchase.manage', 'acc.manage', 'purchase.buy')) && $u->featureOn('purchase.reimbursement')],
                ['Vendor bills', 'pur.bills', 'file', $p('acc.view', 'purchase.manage') && $u->featureOn('purchase.vendor_credit')],
                ['Vendors', ['crud.index', 'vendors'], 'users', $p('purchase.manage') || ($admin && $on('purchase.core'))],
            ],
            'Catering' => [
                ['Events', 'cat.index', 'calendar', $p('catering.manage')],
            ],
            'POS' => [
                ['POS billing', 'pos', 'monitor', $p('pos.sell')],
                ['Kitchen display', 'pos.kds', 'tv', $p('pos.kds')],
                ['POS orders', 'pos.orders', 'list', $p('pos.sell', 'pos.void')],
                ['Table calls', 'calls.index', 'bell', \App\Services\QrOrderService::on() && $p('pos.table_calls')],
                ['Table QR codes', 'qr.codes', 'grid', \App\Services\QrOrderService::on() && $p('pos.qr_codes')],
                ['Home delivery', 'delivery.index', 'truck', \App\Support\Delivery::canView($u)],
                ['Delivery report', 'delivery.report', 'chart', \App\Support\Delivery::on() && $p('delivery.manage', 'payroll.manage', 'delivery.order')],
                ['Day summary', 'pos.summary', 'chart', $p('pos.void', 'reports.management', 'acc.view') && $u->featureOn('pos.core')],
            ],
            'People & HR' => [
                ['Team attendance', 'attendance.team', 'users', $p('attendance.team_view')],
                ['Attendance report', 'attendance.report', 'calendar', $p('attendance.team_view', 'attendance.manage')],
                ['Roster', 'roster', 'grid', $p('shifts.manage')],
                ['Proxy check-in', 'proxy', 'link', $p('attendance.proxy_grant')],
                ['Checklists', 'tasks.templates', 'repeat', $p('tasks.templates')],
                ['Leave', 'leave', 'sun', $p('leave.request', 'leave.manage')],
                ['KPI', 'kpi', 'star', $p('kpi.view_own', 'kpi.view_team')],
                ['Payroll', 'payroll', 'money', $p('payroll.manage', 'acc.manage') && $u->featureOn('payroll.run')],
                ['Salary advances', 'advances', 'money', $p('payroll.advance_request', 'acc.manage', 'payroll.manage')],
                ['My payslips', 'payslips', 'file', $p('payroll.view_own')],
                ['Employees', ['crud.index', 'users'], 'id', $p('hr.employees') || $admin],
            ],
            'Accounting' => [
                ['Overview', 'acc.index', 'bank', $p('acc.view')],
                ['Journal', 'acc.journal', 'book', $p('acc.view')],
                ['Expenses', 'acc.expenses', 'receipt', $p('acc.view')],
                ['Reports', ['acc.report', 'pnl'], 'chart', $p('acc.view')],
            ],
            'Company setup' => [
                ['Locations', ['crud.index', 'locations'], 'map', $admin],
                ['Departments', ['crud.index', 'departments'], 'grid', $admin],
                ['Sale items (menu)', ['crud.index', 'menu_items'], 'monitor', ($admin && $on('pos.core', 'inventory.stock')) || $p('inventory.manage')],
                ['Raw materials & stock', ['crud.index', 'items'], 'box', ($admin && $on('inventory.stock')) || $p('inventory.manage')],
                ['Shifts', ['crud.index', 'shift_templates'], 'clock', ($admin && $on('shifts.roster')) || $p('shifts.manage')],
                ['Leave types', ['crud.index', 'leave_types'], 'sun', ($admin && $on('leave.request')) || $p('leave.manage')],
                ['Holidays', ['crud.index', 'holidays'], 'calendar', ($admin && $on('leave.request', 'payroll.run')) || $p('leave.manage')],
                ['Money accounts', ['crud.index', 'money_accounts'], 'bank', ($admin && $on('acc.core', 'purchase.core', 'pos.core', 'payroll.run')) || $p('acc.manage')],
                ['Dining tables', ['crud.index', 'dining_tables'], 'grid', $admin && $on('pos.core')],
                ['Roles & permissions', 'setup.roles', 'shield', $admin],
                ['Approval rules', 'setup.rules', 'check', $admin],
                ['Delegations', 'setup.delegations', 'link', $admin],
                ['Company settings', 'setup.settings', 'cog', \App\Support\Features::canCompanySettings($u)],
            ],
            'Super Admin' => [
                ['Modules & features', 'super.features', 'toggle', $u->is_super_admin],
                ['Company admins', 'super.admins', 'shield', $u->is_super_admin],
                ['Payment methods', ['crud.index', 'payment_methods'], 'money', $u->is_super_admin],
                ['Audit log', 'super.audit', 'eye', $u->is_super_admin],
            ],
        ];
        $out = [];
        foreach ($groups as $g => $items) {
            $items = array_values(array_filter($items, fn ($i) => $i[3]));
            if ($items) {
                $out[$g] = $items;
            }
        }

        return $out;
    }

    public static function url($route): string
    {
        return is_array($route) ? route($route[0], $route[1]) : route($route);
    }
}
