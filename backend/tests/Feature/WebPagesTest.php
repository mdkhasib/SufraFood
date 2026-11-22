<?php

namespace Tests\Feature;

use App\Models\CateringEvent;
use App\Models\JournalEntry;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\PurchaseAssignment;
use App\Models\Requisition;
use App\Support\Menu;

/** Opens every menu page (plus detail pages) as every demo role: no page may crash. */
class WebPagesTest extends GroupOpsTestCase
{
    public const ROLES = ['SA-0001', 'E-1001', 'E-1002', 'E-1004', 'E-1010', 'E-1020', 'E-1030', 'E-1031', 'E-1040', 'E-1050', 'E-1060', 'E-1070', 'E-2001', 'E-2003', 'E-2004', 'E-2008', 'E-2011'];

    public function test_login_page_and_web_login(): void
    {
        $this->get('/login')->assertOk()->assertSee('Sufra GroupOps');
        $this->post('/login', ['login' => 'E-2001', 'password' => '123456'])->assertRedirect('/');
        $this->get('/')->assertOk()->assertSee('Tanjila');
    }

    public function test_wrong_password_rejected(): void
    {
        $this->post('/login', ['login' => 'E-2001', 'password' => 'nope'])->assertSessionHasErrors('login');
        $this->get('/')->assertRedirect('/login');
    }

    public function test_every_menu_page_renders_for_every_role(): void
    {
        foreach (self::ROLES as $code) {
            $u = $this->u($code);
            $this->actingAs($u);
            $menu = Menu::for($u);
            $this->assertNotEmpty($menu, "$code has a menu");
            foreach ($menu as $group => $items) {
                foreach ($items as $it) {
                    $url = Menu::url($it[1]);
                    $res = $this->get($url);
                    $this->assertSame(200, $res->status(), "$code → {$it[0]} ($url) returned {$res->status()}");
                }
            }
            foreach (['/lang/bn', '/lang/en', '/profile'] as $url) {
                $this->get($url)->assertStatus(in_array($url, ['/lang/bn', '/lang/en']) ? 302 : 200);
            }
        }
    }

    public function test_detail_pages_render(): void
    {
        $this->actingAs($this->u('SA-0001'));
        foreach (Requisition::all() as $r) {
            $this->get(route('req.show', $r))->assertOk()->assertSee($r->number);
        }
        $this->get(route('payroll.run', PayrollRun::first()))->assertOk();
        $this->get(route('payslip', Payslip::first()))->assertOk()->assertSee('Net pay');
        foreach (CateringEvent::all() as $e) {
            $this->get(route('cat.show', $e))->assertOk();
        }
        $this->get(route('acc.entry', JournalEntry::first()))->assertOk();
        foreach (['pnl', 'tb', 'bs', 'daybook'] as $rep) {
            $this->get(route('acc.report', $rep))->assertOk();
        }
        $this->get(route('acc.report', 'tb'))->assertSee('balanced');
        foreach (PurchaseAssignment::all() as $pa) {
            $this->get(route('pur.assignment', $pa))->assertOk();
        }
        $this->get(route('crud.create', 'users'))->assertOk();
        $this->get(route('crud.edit', ['users', $this->u('E-2001')->id]))->assertOk();
        $this->get(route('crud.edit', ['locations', 1]))->assertOk()->assertSee('Use my current location');
        $this->get(route('pos.kds.feed'))->assertOk();
        $this->get(route('pos.receipt', \App\Models\PosOrder::where('status', 'PAID')->first()))->assertOk();
        $this->get(route('roster', ['week' => today()->toDateString()]))->assertOk();
        $this->get(route('prod.index', ['date' => today()->addDay()->toDateString()]))->assertOk();
    }

    public function test_worker_sees_only_basic_menu_and_is_blocked_elsewhere(): void
    {
        $w = $this->u('E-2008');
        $this->actingAs($w);
        $groups = array_keys(Menu::for($w));
        $this->assertEqualsCanonicalizing(['Main', 'People & HR'], $groups);
        $labels = collect(Menu::for($w))->flatten(1)->pluck(0)->all();
        $this->assertNotContains('Requisitions', $labels, 'workers cannot raise requisitions');
        $this->get(route('req.create'))->assertForbidden();
        $this->get(route('pos'))->assertForbidden();
        $this->get(route('acc.index'))->assertForbidden();
        $this->get(route('super.features'))->assertForbidden();
        $this->get(route('setup.roles'))->assertForbidden();
        $this->get(route('crud.index', 'users'))->assertForbidden();
        $this->get(route('attendance.team'))->assertForbidden();
    }

    public function test_company_admin_cannot_use_feature_control(): void
    {
        $this->actingAs($this->u('E-1002'));
        $this->get(route('setup.roles'))->assertOk();
        $this->get(route('setup.rules'))->assertOk();
        $this->get(route('super.features'))->assertForbidden();
        $this->get(route('crud.index', 'payment_methods'))->assertForbidden();
    }
}
