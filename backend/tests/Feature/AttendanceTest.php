<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Attendance;
use App\Models\Roster;
use App\Models\ShiftTemplate;
use App\Services\AttendanceService;

class AttendanceTest extends GroupOpsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setting('attendance.test_mode', 0); // enforce the real geofence
    }

    protected function shiftStartingIn(string $code, int $minutes): void
    {
        $u = $this->u($code);
        $s = ShiftTemplate::create(['code' => 'T'.$minutes, 'name' => 'Test', 'start_time' => now()->addMinutes($minutes)->format('H:i'), 'end_time' => now()->addHours(8)->format('H:i')]);
        Roster::updateOrCreate(['user_id' => $u->id, 'date' => today()->toDateString()], ['shift_template_id' => $s->id, 'is_off' => false, 'location_id' => $u->location_id]);
    }

    public function test_check_in_inside_geofence_on_time_and_lead_notified(): void
    {
        $this->shiftStartingIn('E-2008', 5);
        $this->api('E-2008');
        $this->postJson('/api/attendance/check-in', $this->gps('BR-GUL', 20))->assertOk()->assertJsonPath('attendance.status', 'PRESENT');
        $lead = $this->u('E-2004');
        $this->assertTrue(AppNotification::where('user_id', $lead->id)->where('title', 'like', 'Shanto Das checked in%')->exists());
        $this->postJson('/api/attendance/check-in', $this->gps('BR-GUL'))->assertStatus(422);
    }

    public function test_check_in_outside_geofence_is_blocked_with_distance(): void
    {
        $this->shiftStartingIn('E-2008', 5);
        $this->api('E-2008');
        $res = $this->postJson('/api/attendance/check-in', $this->gps('BR-GUL', 400))->assertStatus(422);
        $this->assertStringContainsString('m from Sufra Gulshan', $res->json('message'));
    }

    public function test_test_mode_allows_anywhere(): void
    {
        $this->setting('attendance.test_mode', 1);
        $this->shiftStartingIn('E-2008', 5);
        $this->api('E-2008');
        $this->postJson('/api/attendance/check-in', $this->gps('BR-GUL', 5000))->assertOk();
    }

    public function test_mock_gps_and_weak_signal_blocked(): void
    {
        $this->shiftStartingIn('E-2008', 5);
        $this->api('E-2008');
        $this->postJson('/api/attendance/check-in', $this->gps('BR-GUL') + ['mocked' => true])->assertStatus(422)->assertJsonFragment(['message' => 'Fake GPS / mock location detected. Check-in blocked.']);
        $this->postJson('/api/attendance/check-in', ['accuracy' => 900] + $this->gps('BR-GUL'))->assertStatus(422);
        $this->assertDatabaseHas('audit_logs', ['action' => 'attendance.mock_gps_blocked']);
    }

    public function test_late_check_in_alerts_lead_and_manager(): void
    {
        $this->shiftStartingIn('E-2008', -25);
        $this->api('E-2008');
        $this->postJson('/api/attendance/check-in', $this->gps('BR-GUL'))->assertOk()->assertJsonPath('attendance.status', 'LATE');
        $this->assertTrue(AppNotification::where('user_id', $this->u('E-2001')->id)->where('title', 'like', '%checked in late%')->exists());
    }

    public function test_proxy_rules(): void
    {
        $this->shiftStartingIn('E-2004', 5);
        $this->shiftStartingIn('E-2007', 5);
        $this->shiftStartingIn('E-2008', 5);
        $this->api('E-2004');
        $rubel = $this->u('E-2007');
        // proxy must check in first
        $this->postJson('/api/attendance/proxy-check-in', ['user_id' => $rubel->id] + $this->gps('BR-GUL'))->assertStatus(422)->assertJsonFragment(['message' => 'Check yourself in first.']);
        $this->postJson('/api/attendance/check-in', $this->gps('BR-GUL'))->assertOk();
        // only people on the fixed list
        $this->postJson('/api/attendance/proxy-check-in', ['user_id' => $this->u('E-2008')->id] + $this->gps('BR-GUL'))->assertStatus(422);
        // proxy must be inside the area
        $this->postJson('/api/attendance/proxy-check-in', ['user_id' => $rubel->id] + $this->gps('BR-GUL', 500))->assertStatus(422);
        $this->getJson('/api/attendance/proxy-list')->assertOk()->assertJsonCount(2);
        $this->postJson('/api/attendance/proxy-check-in', ['user_id' => $rubel->id] + $this->gps('BR-GUL'))->assertOk();
        $a = Attendance::where('user_id', $rubel->id)->whereDate('date', today())->first();
        $this->assertSame('PROXY', $a->method);
        $this->assertSame($this->u('E-2004')->id, $a->by_user_id);
        $this->assertTrue(AppNotification::where('user_id', $this->u('E-2001')->id)->where('title', 'like', '%checked in Rubel Mia (proxy)%')->exists(), 'manager notified');
        $this->assertTrue(AppNotification::where('user_id', $rubel->id)->where('title', 'like', 'You were checked in by%')->exists(), 'person notified');
    }

    public function test_manager_grant_limits(): void
    {
        $this->api('E-2001');
        $ids = [$this->u('E-2005')->id, $this->u('E-2006')->id, $this->u('E-2010')->id];
        $this->postJson('/api/attendance/proxy-grants', ['proxy_user_id' => $this->u('E-2003')->id, 'staff_ids' => $ids])->assertStatus(422); // max 2
        $this->postJson('/api/attendance/proxy-grants', ['proxy_user_id' => $this->u('E-2003')->id, 'staff_ids' => [$this->u('E-3005')->id]])->assertStatus(422); // other branch
        $this->postJson('/api/attendance/proxy-grants', ['proxy_user_id' => $this->u('E-2003')->id, 'staff_ids' => array_slice($ids, 0, 2)])->assertOk();
        $this->api('E-2008');
        $this->postJson('/api/attendance/proxy-grants', ['proxy_user_id' => $this->u('E-2005')->id, 'staff_ids' => [$this->u('E-2006')->id]])->assertForbidden();
    }

    public function test_no_show_alert_and_check_out_with_overtime(): void
    {
        $this->shiftStartingIn('E-2008', -30);
        Roster::where('user_id', $this->u('E-2008')->id)->update(['no_show_notified' => false]);
        $n = AttendanceService::noShowCheck();
        $this->assertGreaterThanOrEqual(1, $n);
        $this->assertTrue(AppNotification::where('user_id', $this->u('E-2004')->id)->where('title', 'like', '%Shanto Das not checked in%')->exists());

        $this->api('E-2008');
        $this->postJson('/api/attendance/check-in', $this->gps('BR-GUL'))->assertOk();
        $this->postJson('/api/attendance/check-out', $this->gps('BR-GUL'))->assertOk()->assertJsonStructure(['attendance' => ['worked_minutes']]);
    }

    public function test_correction_request_goes_to_direct_manager(): void
    {
        $this->api('E-2008');
        $this->postJson('/api/attendance/corrections', ['date' => today()->subDays(2)->toDateString(), 'check_in' => '08:00', 'check_out' => '16:00', 'reason' => 'phone dead'])->assertOk();
        $this->api('E-2004');
        $inbox = $this->getJson('/api/approvals')->assertOk()->json();
        $step = collect($inbox)->first(fn ($s) => $s['type'] === 'ATTENDANCE_CORRECTION' && $s['requester'] === 'Shanto Das');
        $this->assertNotNull($step, 'direct manager (team lead) gets it');
        $this->postJson('/api/approvals/'.$step['id'], ['approve' => true])->assertOk();
        $this->assertDatabaseHas('attendances', ['user_id' => $this->u('E-2008')->id, 'method' => 'CORRECTION']);
    }

    public function test_team_board_and_month_calendar(): void
    {
        $this->api('E-2001');
        $b = $this->getJson('/api/attendance/team')->assertOk()->json();
        $this->assertGreaterThanOrEqual(15, count($b['rows']));
        $this->assertContains('PROXY', array_column($b['rows'], 'method'));
        $this->getJson('/api/attendance/month')->assertOk()->assertJsonStructure(['days', 'summary']);
        $this->api('E-2008');
        $this->getJson('/api/attendance/team')->assertForbidden();
    }
}
