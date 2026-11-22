<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\FeatureModule;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Services\TaskService;
use App\Support\Features;
use App\Support\Menu;

/** Teams, org chart, assign list, reminders, module switches, company settings, login by ID or mobile. */
class TeamsOrgTest extends GroupOpsTestCase
{
    protected function notes(string $code): \Illuminate\Support\Collection
    {
        return AppNotification::where('user_id', $this->u($code)->id)->latest('id')->pluck('title');
    }

    public function test_md_assign_list_shows_leaders_not_waiters(): void
    {
        $this->api('E-1001'); // MD
        $people = collect($this->getJson('/api/assign-options')->assertOk()->json('people'));
        $codes = $people->pluck('code');
        $this->assertContains('E-1002', $codes, 'GM reports to MD');
        $this->assertContains('E-2001', $codes, 'branch manager leads people');
        $this->assertContains('E-2004', $codes, 'kitchen team lead');
        $this->assertNotContains('E-2012', $codes, 'waiter is not in the MD list');
        $this->assertNotContains('E-2008', $codes, 'kitchen helper is not in the MD list');
        $this->assertTrue($people->firstWhere('code', 'E-1002')['direct']);

        // a team lead still sees their own front-line people (direct reports)
        $this->api('E-2004');
        $this->assertContains('E-2008', collect($this->getJson('/api/assign-options')->json('people'))->pluck('code'));

        // company setting ALL shows everyone below
        $this->setting('tasks.assign_list', 'ALL');
        $this->api('E-2001');
        $this->assertContains('E-2012', collect($this->getJson('/api/assign-options')->json('people'))->pluck('code'));
    }

    public function test_assign_to_team_lead_or_every_member(): void
    {
        $kitchen = Team::where('name', 'Gulshan Kitchen')->firstOrFail();
        $this->api('E-2001');
        $this->assertContains($kitchen->id, collect($this->getJson('/api/assign-options')->json('teams'))->pluck('id'));

        $this->postJson('/api/tasks', ['title' => 'Deep clean kitchen', 'due_at' => now()->addHours(3), 'team_ids' => [$kitchen->id], 'team_mode' => 'EACH'])->assertOk();
        $each = Task::where('title', 'Deep clean kitchen')->get();
        $this->assertCount(6, $each, 'one task per kitchen member');
        $this->assertTrue($each->every(fn ($t) => $t->team_id === $kitchen->id));

        $this->postJson('/api/tasks', ['title' => 'Plan kitchen rota', 'due_at' => now()->addHours(3), 'team_ids' => [$kitchen->id], 'team_mode' => 'LEAD'])->assertOk();
        $lead = Task::where('title', 'Plan kitchen rota')->get();
        $this->assertCount(1, $lead);
        $this->assertEquals($this->u('E-2004')->id, $lead->first()->assignee_id);

        // a Dhanmondi team is not under the Gulshan manager
        $dhn = Team::where('name', 'Dhanmondi Kitchen')->firstOrFail();
        $this->postJson('/api/tasks', ['title' => 'x', 'due_at' => now()->addHour(), 'team_ids' => [$dhn->id]])->assertStatus(422);
    }

    public function test_org_chart_tree_scope_and_reports_to_rules(): void
    {
        // branch manager sees their own subtree
        $this->api('E-2001');
        $tree = $this->getJson('/api/org')->assertOk()->json('tree');
        $this->assertCount(1, $tree);
        $this->assertEquals('E-2001', $tree[0]['code']);
        $this->assertGreaterThanOrEqual(17, $tree[0]['below']);

        // GM (group-wide) sees the whole company from the MD down
        $this->api('E-1002');
        $tree = $this->getJson('/api/org')->json('tree');
        $this->assertEquals('E-1001', $tree[0]['code']);
        $this->actingAs($this->u('E-1002'))->get(route('org'))->assertOk()->assertSee('Tanjila Ahmed')->assertSee('Gulshan Kitchen');
        $this->actingAs($this->u('E-1002'))->get(route('org', ['view' => 'list', 'root' => $this->u('E-2001')->id]))->assertOk()->assertSee('Shanto Das');
        $this->actingAs($this->u('E-1002'))->get(route('teams'))->assertOk()->assertSee('Gulshan Floor (FOH)');

        // reports-to: no loops
        $this->actingAs($this->u('E-1002'))->post(route('org.manager', $this->u('E-2001')), ['manager_id' => $this->u('E-2008')->id])->assertSessionHas('error');
        $this->actingAs($this->u('E-1020'))->post(route('org.manager', $this->u('E-2008')), ['manager_id' => $this->u('E-2003')->id])->assertSessionHas('ok');
        $this->assertEquals($this->u('E-2003')->id, $this->u('E-2008')->manager_id);

        // workers cannot see the org chart
        $this->actingAs($this->u('E-2008'))->get(route('org'))->assertForbidden();
        $this->assertNotContains('Org chart', collect(Menu::for($this->u('E-2008')))->flatten(1)->pluck(0));
    }

    public function test_branch_manager_builds_teams_only_at_own_branch(): void
    {
        $mgr = $this->u('E-2001');
        $gul = $mgr->location_id;
        $this->actingAs($mgr)->post(route('teams.save'), ['name' => 'Tandoor Corner', 'location_id' => $gul, 'lead_id' => $this->u('E-2010')->id, 'members' => [$this->u('E-2005')->id, $this->u('E-2006')->id], 'report_to_lead' => 1])->assertSessionHas('ok');
        $t = Team::where('name', 'Tandoor Corner')->firstOrFail();
        $this->assertEquals($t->id, $this->u('E-2005')->team_id);
        $this->assertEquals($this->u('E-2010')->id, $this->u('E-2005')->manager_id, 'members now report to the lead');

        $this->actingAs($mgr)->post(route('teams.save'), ['name' => 'Elsewhere', 'location_id' => $this->u('E-3001')->location_id])->assertSessionHas('error');
        $this->actingAs($mgr)->delete(route('teams.delete', $t))->assertSessionHas('ok');
        $this->assertNull($this->u('E-2005')->team_id);
    }

    public function test_reminders_before_due_repeat_when_late_and_manual_nudge(): void
    {
        $lead = $this->u('E-2004');
        auth()->setUser($lead);
        [$t] = TaskService::create($lead, ['title' => 'Prep chutney', 'due_at' => now()->addMinutes(20)->toDateTimeString(), 'remind_before_minutes' => 30], [$this->u('E-2008')->id]);
        TaskService::sendReminders();
        $this->assertNotNull($t->fresh()->pre_reminded_at);
        $this->assertTrue($this->notes('E-2008')->contains('Reminder: Prep chutney'));
        TaskService::sendReminders(); // only once
        $this->assertEquals(1, AppNotification::where('user_id', $this->u('E-2008')->id)->where('title', 'Reminder: Prep chutney')->count());

        // top priority overdue: repeats every 15 min (setting), capped
        [$u] = TaskService::create($lead, ['title' => 'Fix gas leak', 'priority' => 'URGENT', 'due_at' => now()->subMinutes(5)->toDateTimeString()], [$this->u('E-2005')->id]);
        TaskService::sendReminders();
        $this->assertEquals(1, $u->fresh()->reminders_sent);
        TaskService::sendReminders();
        $this->assertEquals(1, $u->fresh()->reminders_sent, 'not again within 15 min');
        $u->update(['last_reminded_at' => now()->subMinutes(16)]);
        TaskService::sendReminders();
        $this->assertEquals(2, $u->fresh()->reminders_sent);
        $this->assertTrue($this->notes('E-2005')->contains('TOP PRIORITY overdue: Fix gas leak'));

        // normal priority without a repeat interval: no nagging
        [$n] = TaskService::create($lead, ['title' => 'Wipe shelves', 'due_at' => now()->subMinutes(30)->toDateTimeString()], [$this->u('E-2006')->id]);
        TaskService::sendReminders();
        $this->assertEquals(0, $n->fresh()->reminders_sent);

        // manual nudge by the assigner; the worker cannot nudge; throttled
        $this->api('E-2004');
        $this->postJson("/api/tasks/{$n->id}/remind")->assertOk();
        $this->postJson("/api/tasks/{$n->id}/remind")->assertStatus(422);
        $this->assertTrue($this->notes('E-2006')->contains(fn ($x) => str_starts_with($x, 'Reminder from Shafiq Ahmed')));
        $this->api('E-2006');
        $this->postJson("/api/tasks/{$n->id}/remind")->assertForbidden();
    }

    public function test_assigner_is_told_when_task_is_started_and_finished(): void
    {
        $this->api('E-2004');
        $this->postJson('/api/tasks', ['title' => 'Slice onions', 'due_at' => now()->addHour(), 'assignee_ids' => [$this->u('E-2008')->id], 'notify_on_start' => true, 'notify_on_done' => true])->assertOk();
        $this->postJson('/api/tasks', ['title' => 'Quiet task', 'due_at' => now()->addHour(), 'assignee_ids' => [$this->u('E-2008')->id], 'notify_on_done' => false])->assertOk();
        $a = Task::where('title', 'Slice onions')->first();
        $b = Task::where('title', 'Quiet task')->first();

        $this->api('E-2008');
        $this->postJson("/api/tasks/{$a->id}/start")->assertOk();
        $this->postJson("/api/tasks/{$a->id}/complete")->assertOk()->assertJsonPath('status', 'DONE');
        $this->postJson("/api/tasks/{$b->id}/complete")->assertOk();
        $n = $this->notes('E-2004');
        $this->assertTrue($n->contains('Task started: Slice onions'));
        $this->assertTrue($n->contains('Task finished: Slice onions'));
        $this->assertFalse($n->contains('Task finished: Quiet task'));
    }

    public function test_module_switch_hides_features_for_everyone_including_super_admin(): void
    {
        $sa = $this->u('SA-0001');
        $cat = FeatureModule::where('name', 'Catering')->firstOrFail();
        $this->actingAs($sa)->post(route('super.modules.update', $cat), ['status' => 'OFF'])->assertSessionHas('ok');
        Features::flush();
        $this->assertFalse($this->u('E-1070')->hasPerm('catering.manage'));
        $this->assertNotContains('catering.events', $this->u('E-1002')->enabledFeatures());
        $this->assertNotContains('Events', collect(Menu::for($this->u('SA-0001')))->flatten(1)->pluck(0), 'super admin menu follows the switches');
        $this->actingAs($this->u('E-1070'))->get(route('cat.index'))->assertForbidden();

        // a feature cannot be switched on inside an OFF module
        $f = \App\Models\Feature::where('key', 'catering.events')->first();
        $f->update(['status' => 'OFF']);
        $this->actingAs($sa)->post(route('super.features.update', $f), ['status' => 'ON'])->assertSessionHas('error');

        // Tasks module cannot go OFF while KPI (another module) needs it
        $tasks = FeatureModule::where('name', 'Tasks')->first();
        $this->actingAs($sa)->post(route('super.modules.update', $tasks), ['status' => 'OFF'])->assertSessionHas('error');

        // back ON with all its features
        $this->actingAs($sa)->post(route('super.modules.update', $cat), ['status' => 'ON', 'with_features' => 1])->assertSessionHas('ok');
        Features::flush();
        $this->assertTrue($this->u('E-1070')->hasPerm('catering.manage'));
        $this->actingAs($sa)->get(route('super.features'))->assertOk()->assertSee('Turn module OFF');
    }

    public function test_company_settings_are_super_admin_only_until_allowed(): void
    {
        $gm = $this->u('E-1002');
        $this->actingAs($gm)->get(route('setup.settings'))->assertForbidden();
        $this->assertNotContains('Company settings', collect(Menu::for($gm))->flatten(1)->pluck(0));

        $sa = $this->u('SA-0001');
        $this->actingAs($sa)->get(route('setup.settings'))->assertOk()->assertSee('Salary advance')->assertSee('Sufra Food Ltd');
        $mod = FeatureModule::where('name', 'Company settings')->first();
        $this->actingAs($sa)->post(route('super.modules.update', $mod), ['admin_access' => 1])->assertSessionHas('ok');
        Features::flush();
        $this->actingAs($this->u('E-1002'))->get(route('setup.settings'))->assertOk();
        $this->actingAs($this->u('E-1002'))->post(route('setup.settings.save'), ['advance__max_percent' => 50, 'advance__one_open_only__present' => 1])->assertSessionHas('ok');
        Features::flush();
        $this->assertEquals(50, Features::setting('advance.max_percent'));
        $this->assertEquals(0, (int) Features::setting('advance.one_open_only'), 'unticked checkbox saved as off');
        $this->assertEquals(1, (int) Features::setting('attendance.test_mode'), 'super-only setting untouched by the GM');

        // the rule now applies: 50% of basic
        $this->api('E-2008');
        $basic = $this->u('E-2008')->basic_salary;
        $this->postJson('/api/salary-advances', ['amount' => $basic * 0.6, 'months' => 2])->assertStatus(422);
        $this->postJson('/api/salary-advances', ['amount' => $basic * 0.4, 'months' => 2])->assertOk();
    }

    public function test_role_permissions_one_page_overview_and_save(): void
    {
        $gm = $this->u('E-1002');
        $this->actingAs($gm)->get(route('setup.roles'))->assertOk()->assertSee('Overview: who has what')->assertSee('Edit all permissions')->assertSee('Team &amp; Org', false);
        $role = \App\Models\Role::where('key', 'cashier')->first();
        $grid = \Illuminate\Support\Facades\DB::table('role_permissions')->get()->groupBy('role_id')->map(fn ($g) => $g->pluck('permission')->all())->all();
        $grid[$role->id][] = 'teams.view';
        $this->actingAs($gm)->post(route('setup.roles.save'), ['perm' => $grid])->assertRedirect();
        $this->assertContains('teams.view', $role->permissionKeys());
        $this->assertTrue($this->u('E-2011')->hasPerm('teams.view'));
        $this->actingAs($gm)->post(route('setup.roles.update', $role), ['name' => 'Cashier / Front desk', 'tier' => 'FUNCTION', 'scope' => 'LOCATION'])->assertSessionHas('ok');
        $this->assertEquals('Cashier / Front desk', $role->fresh()->name);
    }

    public function test_login_with_employee_id_or_mobile_number_in_any_format(): void
    {
        $phone = $this->u('E-2008')->phone; // 017xxxxxxxx
        foreach (['E-2008', 'e2008', ' e-2008 ', $phone, '+88'.$phone, '88'.$phone, substr($phone, 1), '+880 '.substr($phone, 1, 4).'-'.substr($phone, 5)] as $login) {
            $this->postJson('/api/login', ['login' => $login, 'password' => '123456'])->assertOk()->assertJsonPath('user.employee_code', 'E-2008');
        }
        $this->postJson('/api/login', ['login' => '01999999999', 'password' => '123456'])->assertStatus(422);
        $this->post('/login', ['login' => '+88'.$phone, 'password' => '123456', 'mode' => 'phone'])->assertRedirect('/');
        $this->assertAuthenticatedAs($this->u('E-2008'));
        $this->get('/login'); // logged-in users are redirected
        auth()->logout();
        $this->get('/login')->assertOk()->assertSee('Mobile number')->assertSee('Employee ID');

        // phone numbers are stored normalised when HR types them with spaces / +880
        $u = $this->u('E-2009');
        $u->update(['phone' => '+880 1811-223344']);
        $this->assertEquals('01811223344', $u->fresh()->phone);
    }
}
