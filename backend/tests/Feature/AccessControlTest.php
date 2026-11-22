<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Support\Menu;
use Illuminate\Support\Facades\DB;

/** The four gates: feature ON → at location (pilot) → role permission → scope / limit. */
class AccessControlTest extends GroupOpsTestCase
{
    public function test_feature_off_hides_menu_and_blocks_api(): void
    {
        $this->feature('pos.kds', 'OFF');
        $this->feature('pos.recipe_deduction', 'OFF');
        $this->feature('pos.core', 'OFF');
        $cashier = $this->u('E-2011');
        $this->assertFalse($cashier->hasPerm('pos.sell'));
        $this->actingAs($cashier)->get(route('pos'))->assertForbidden();
        $labels = collect(Menu::for($cashier))->flatten(1)->pluck(0)->all();
        $this->assertNotContains('POS billing', $labels);

        $this->feature('attendance.proxy_checkin', 'OFF');
        $this->api('E-2004');
        $this->getJson('/api/attendance/proxy-list')->assertOk()->assertExactJson([]);
        $this->assertNotContains('attendance.proxy_grant', $this->u('E-2001')->effectivePermissions());
    }

    public function test_pilot_feature_only_at_pilot_locations_but_group_wide_sees_it(): void
    {
        $this->feature('req.food_order', 'PILOT', ['BR-GUL', 'CK']);
        $this->assertTrue($this->u('E-2001')->hasPerm('req.create.food_order'), 'Gulshan manager in pilot');
        $this->assertFalse($this->u('E-3001')->hasPerm('req.create.food_order'), 'Dhanmondi manager not in pilot');
        $this->assertTrue($this->u('E-1002')->featureOn('req.food_order'), 'GM (group-wide) sees pilot');
        $this->api('E-3001');
        $this->postJson('/api/requisitions', ['type' => 'FOOD_ORDER', 'lines' => [['item_id' => 1, 'qty' => 1]]])->assertStatus(422);
    }

    public function test_role_permission_matrix_controls_actions(): void
    {
        $sup = Role::where('key', 'supervisor')->first();
        $this->assertFalse($this->u('E-2004')->hasPerm('req.create.supplies'));
        DB::table('role_permissions')->insert(['role_id' => $sup->id, 'permission' => 'req.create.supplies']);
        $this->assertTrue($this->u('E-2004')->hasPerm('req.create.supplies'), 'company admin can grant a role');
        // worker never raises requests
        $this->api('E-2008');
        $this->getJson('/api/requisition-types')->assertOk()->assertJsonCount(0, 'types');
    }

    public function test_feature_dependencies_enforced_on_activation(): void
    {
        $this->feature('payroll.payslip_mobile', 'OFF');
        $this->feature('payroll.advance_loan', 'OFF');
        $this->feature('kpi.payroll_link', 'OFF');
        $this->feature('payroll.run', 'OFF');
        $this->actingAs($this->u('SA-0001'));
        $f = \App\Models\Feature::where('key', 'payroll.payslip_mobile')->first();
        $this->post(route('super.features.update', $f), ['status' => 'ON'])->assertSessionHas('error');
        $this->assertSame('OFF', $f->fresh()->status);
        $att = \App\Models\Feature::where('key', 'attendance.gps_checkin')->first();
        $this->post(route('super.features.update', $att), ['status' => 'OFF'])->assertSessionHas('error');
        $this->post(route('super.features.update', \App\Models\Feature::where('key', 'payroll.run')->first()), ['status' => 'PILOT', 'locations' => [5]])->assertSessionHas('ok');
        $this->assertDatabaseHas('audit_logs', ['action' => 'feature.pilot']);
    }

    public function test_scope_task_assignment_only_to_own_team(): void
    {
        $this->api('E-2004'); // Gulshan kitchen lead
        $this->postJson('/api/tasks', ['title' => 'x', 'due_at' => now()->addHour(), 'assignee_ids' => [$this->u('E-2005')->id]])->assertOk();
        $this->postJson('/api/tasks', ['title' => 'x', 'due_at' => now()->addHour(), 'assignee_ids' => [$this->u('E-3005')->id]])->assertStatus(422);
        $this->api('E-2008');
        $this->postJson('/api/tasks', ['title' => 'x', 'due_at' => now()->addHour(), 'assignee_ids' => [$this->u('E-2005')->id]])->assertForbidden();
    }

    public function test_personal_approval_limit_blocks_large_approval(): void
    {
        $this->api('E-1002');
        $step = \App\Models\ApprovalStep::where('status', 'PENDING')->get()->first(fn ($s) => $s->approvable instanceof \App\Models\Requisition && $s->approvable->type === 'EQUIPMENT');
        $this->u('E-1002')->update(['approval_limit' => 1000]);
        $this->api('E-1002');
        $this->postJson('/api/approvals/'.$step->id, ['approve' => true])->assertStatus(422)->assertJsonFragment(['message' => 'Amount ৳26,000 is above your approval limit of ৳1,000.']);
    }

    public function test_company_admin_cannot_change_features_but_can_change_permissions(): void
    {
        $gm = $this->u('E-1002');
        $this->actingAs($gm);
        $f = \App\Models\Feature::first();
        $this->post(route('super.features.update', $f), ['status' => 'OFF'])->assertForbidden();
        $this->post(route('setup.roles.save'), ['perm' => []])->assertRedirect();
    }
}
