<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\JournalLine;
use App\Models\Location;
use App\Models\MoneyAccount;
use App\Models\PaymentMethod;
use App\Models\PayrollRun;
use App\Models\Requisition;
use App\Models\User;
use App\Models\Vendor;
use App\Services\PayrollService;
use App\Services\StockService;
use Illuminate\Http\UploadedFile;

class WorkflowTest extends GroupOpsTestCase
{
    protected function item(string $code): int
    {
        return Item::where('code', $code)->value('id');
    }

    protected function approveAllAs(?string $code = null): void
    {
        for ($i = 0; $i < 6; $i++) {
            $step = \App\Models\ApprovalStep::where('status', 'PENDING')->latest('id')->first();
            if (! $step) {
                return;
            }
            $who = \App\Services\ApprovalService::approversFor($step)->first();
            $this->api($who->employee_code);
            $this->postJson('/api/approvals/'.$step->id, ['approve' => true])->assertOk();
        }
    }

    protected function assertLedgerBalanced(): void
    {
        $this->assertEqualsWithDelta((float) JournalLine::sum('debit'), (float) JournalLine::sum('credit'), 0.01, 'ledger balanced');
    }

    public function test_full_chain_branch_to_ck_to_warehouse_to_purchase_and_back(): void
    {
        $tomorrow = today()->addDays(2)->setTime(11, 0)->toDateTimeString();
        // 1. Branch manager orders food from CK
        $this->api('E-2001');
        $fo = $this->postJson('/api/requisitions', ['type' => 'FOOD_ORDER', 'needed_by' => $tomorrow, 'lines' => [['item_id' => $this->item('SF-004'), 'qty' => 30]]])->assertOk()->json('requisition');
        $this->assertSame('SENT_TO_FULFILLER', $fo['status']);
        // 2. CK head chef accepts, plans production, requests raw material
        $chef = $this->api('E-1050');
        $this->assertContains('accept', $this->getJson('/api/requisitions/'.$fo['id'])->json('actions'));
        $this->postJson("/api/requisitions/{$fo['id']}/accept")->assertOk();
        $this->actingAs($chef)->post(route('prod.action', 'plan'), ['date' => today()->addDays(2)->toDateString()])->assertSessionHas('ok');
        $this->post(route('prod.action', 'raw'), ['date' => today()->addDays(2)->toDateString()])->assertSessionHas('ok');
        $rm = Requisition::where('type', 'RAW_MATERIAL')->latest('id')->first();
        $this->assertSame('SENT_TO_FULFILLER', $rm->status);
        $this->assertTrue($rm->parents()->where('requisitions.id', $fo['id'])->exists(), 'raw material linked to the food order');
        // 3. Warehouse is short of chicken → shortage purchase
        $this->api('E-1040');
        $chickenLine = $rm->lines()->where('item_id', $this->item('RM-002'))->first();
        $wh = Location::where('code', 'WH')->value('id');
        $this->postJson("/api/requisitions/{$rm->id}/accept", ['qtys' => [$chickenLine->id => StockService::qty($wh, $this->item('RM-002'))]])->assertOk()->assertJsonPath('requisition.status', 'PARTIALLY_ACCEPTED');
        $pr = $this->postJson("/api/requisitions/{$rm->id}/shortage")->assertOk()->json();
        $prReq = Requisition::where('type', 'PURCHASE')->latest('id')->first();
        $this->assertStringContainsString($prReq->number, $pr['message']);
        // branch can see where it is stuck
        $this->api('E-2001');
        $this->assertStringContainsString('Central Kitchen', $this->getJson('/api/requisitions/'.$fo['id'])->json('waiting_on'));
        // 4. purchase approval + assign buyer with 1 hour deadline
        $this->approveAllAs();
        $this->assertSame('SENT_TO_FULFILLER', $prReq->fresh()->status);
        $this->api('E-1030');
        $this->postJson("/api/purchase/requests/{$prReq->id}/assign", ['buyer_id' => $this->u('E-1031')->id, 'deadline_at' => now()->addHour(), 'funding' => 'CASH_ADVANCE', 'payment_method_id' => PaymentMethod::where('name', 'Cash')->value('id')])->assertOk();
        // 5. buyer: accept → receipt photo → delivered
        $this->api('E-1031');
        $pa = collect($this->getJson('/api/purchases')->json())->firstWhere('requisition_id', $prReq->id);
        $this->assertNotNull($pa);
        $walletBefore = $this->getJson('/api/wallet')->json('balance');
        $this->postJson("/api/purchases/{$pa['id']}/accept")->assertOk();
        $lineId = $pa['lines'][0]['id'];
        $this->post("/api/purchases/{$pa['id']}/receipt", ['lines' => json_encode([$lineId => ['qty' => $pa['lines'][0]['qty'], 'unit_price' => 230]]), 'photo' => UploadedFile::fake()->image('r.jpg')], ['Accept' => 'application/json'])->assertOk();
        $this->postJson("/api/purchases/{$pa['id']}/delivered")->assertOk();
        // 6. warehouse goods received → stock up; then dispatch raw material to CK
        $this->api('E-1041');
        $this->postJson("/api/requisitions/{$prReq->id}/receive")->assertOk()->assertJsonPath('requisition.status', 'CLOSED');
        $this->api('E-1031');
        $this->assertLessThan($walletBefore, $this->getJson('/api/wallet')->json('balance'), 'advance spent');
        $this->api('E-1040');
        $qtys = $rm->fresh()->lines->mapWithKeys(fn ($l) => [$l->id => $l->qty_requested])->all();
        $this->postJson("/api/requisitions/{$rm->id}/dispatch", ['qtys' => $qtys])->assertOk();
        $this->api('E-1050');
        $this->postJson("/api/requisitions/{$rm->id}/receive")->assertOk();
        // 7. production done → dispatch food with driver → driver delivers → branch receives
        foreach (\App\Models\ProductionOrder::whereDate('planned_for', today()->addDays(2))->get() as $po) {
            $this->postJson("/api/production/{$po->id}/complete")->assertOk();
        }
        $this->postJson("/api/requisitions/{$fo['id']}/dispatch", ['driver_id' => $this->u('E-1060')->id, 'vehicle' => 'Van 1'])->assertOk();
        $this->api('E-1060');
        $d = collect($this->getJson('/api/deliveries')->json())->firstWhere('requisition_id', $fo['id']);
        $this->postJson("/api/deliveries/{$d['id']}/pickup")->assertOk();
        $this->postJson("/api/deliveries/{$d['id']}/deliver")->assertOk();
        $gul = Location::where('code', 'BR-GUL')->value('id');
        $before = StockService::qty($gul, $this->item('SF-004'));
        $this->api('E-2001');
        $this->postJson("/api/requisitions/{$fo['id']}/receive")->assertOk()->assertJsonPath('requisition.status', 'CLOSED');
        $this->assertEqualsWithDelta($before + 30, StockService::qty($gul, $this->item('SF-004')), 0.01);
        $this->assertLedgerBalanced();
    }

    public function test_purchase_funding_modes_petty_own_money_vendor_credit(): void
    {
        $mk = function (string $code, float $qty) {
            $this->api('E-1040');
            $this->postJson('/api/requisitions', ['type' => 'PURCHASE', 'needed_by' => now()->addDay(), 'lines' => [['item_id' => $this->item($code), 'qty' => $qty]]])->assertOk();
            $r = Requisition::where('type', 'PURCHASE')->latest('id')->first();
            $this->approveAllAs();

            return $r->fresh();
        };
        $buy = function (Requisition $r, array $assign, float $price) {
            $this->api('E-1030');
            $this->postJson("/api/purchase/requests/{$r->id}/assign", $assign + ['buyer_id' => $this->u('E-1032')->id, 'deadline_at' => now()->addDay()])->assertOk();
            $this->api('E-1032');
            $pa = $r->fresh()->purchaseAssignment;
            $this->postJson("/api/purchases/{$pa->id}/accept")->assertOk();
            $this->post("/api/purchases/{$pa->id}/receipt", ['lines' => json_encode([$r->lines[0]->id => ['qty' => $r->lines[0]->qty_requested, 'unit_price' => $price]]), 'photo' => UploadedFile::fake()->image('x.jpg')], ['Accept' => 'application/json'])->assertOk();
            $this->postJson("/api/purchases/{$pa->id}/delivered")->assertOk();
            $this->api('E-1040');
            $this->postJson("/api/requisitions/{$r->id}/receive")->assertOk();

            return $pa->fresh();
        };
        // petty cash
        $petty = MoneyAccount::where('name', 'Petty Cash – Warehouse')->first();
        $before = $petty->balance();
        $buy($mk('RM-009', 10), ['funding' => 'PETTY_CASH', 'petty_account_id' => $petty->id], 45);
        $this->assertEqualsWithDelta($before - 450, $petty->balance(), 0.01);
        // own money → refund claim → approvals → accounts pays via bKash
        $pa = $buy($mk('RM-015', 2), ['funding' => 'OWN_MONEY'], 170);
        $claim = \App\Models\ReimbursementClaim::where('purchase_assignment_id', $pa->id)->first();
        $this->assertNotNull($claim);
        $this->api('E-1032');
        $this->assertEquals(340, $this->getJson('/api/wallet')->json('company_owes'));
        $this->approveAllAs();
        $bkash = MoneyAccount::where('name', 'like', 'bKash%')->first();
        $this->actingAs($this->u('E-1010'))->post(route('pur.claims.pay', $claim), ['money_account_id' => $bkash->id])->assertSessionHas('ok');
        $this->assertSame('PAID', $claim->fresh()->status);
        // vendor credit → bill → part payment by bank
        $vendor = Vendor::where('name', 'like', 'Modina%')->first();
        $pa = $buy($mk('RM-007', 3), ['funding' => 'VENDOR_CREDIT', 'vendor_id' => $vendor->id], 1850);
        $bill = \App\Models\VendorBill::where('purchase_assignment_id', $pa->id)->first();
        $this->assertEquals(5550, $bill->amount);
        $this->assertEquals(today()->addDays(7)->toDateString(), $bill->due_date->toDateString());
        $bank = MoneyAccount::where('type', 'BANK')->first();
        $this->actingAs($this->u('E-1010'))->post(route('pur.bills.pay', $bill), ['money_account_id' => $bank->id, 'amount' => 2000])->assertSessionHas('ok');
        $this->assertSame('PARTIAL', $bill->fresh()->status);
        $this->assertLedgerBalanced();
    }

    public function test_cash_advance_limit_and_price_flag(): void
    {
        $this->actingAs($this->u('E-1010'));
        $cash = MoneyAccount::where('name', 'HO Main Cash')->first();
        $this->post(route('pur.wallet.action', [$this->u('E-1031'), 'issue']), ['amount' => 50000, 'money_account_id' => $cash->id])->assertSessionHas('error');
        $this->post(route('pur.wallet.action', [$this->u('E-1031'), 'issue']), ['amount' => 1000, 'money_account_id' => $cash->id])->assertSessionHas('ok');
        // the seeded open purchase (green chili + tomato) bought far above last price → flagged
        $this->api('E-1031');
        $pa = collect($this->getJson('/api/purchases')->json())->firstWhere('status', 'ASSIGNED');
        $lines = collect($pa['lines'])->mapWithKeys(fn ($l) => [$l['id'] => ['qty' => $l['qty'], 'unit_price' => $l['last_price'] * 1.5]])->all();
        $this->post("/api/purchases/{$pa['id']}/receipt", ['lines' => json_encode($lines), 'photo' => UploadedFile::fake()->image('x.jpg')], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('purchase.price_flag', true);
    }

    public function test_payments_cannot_overdraw_money_accounts(): void
    {
        $petty = MoneyAccount::where('name', 'Petty Cash – Warehouse')->first(); // ৳5,000
        $this->actingAs($this->u('E-1010'));
        $this->post(route('pur.wallet.action', [$this->u('E-1033'), 'issue']), ['amount' => 6000, 'money_account_id' => $petty->id])->assertSessionHas('error');
        $this->post(route('acc.transfer'), ['from' => $petty->id, 'to' => MoneyAccount::where('type', 'BANK')->value('id'), 'amount' => 999999])->assertSessionHas('error');
        $this->assertGreaterThanOrEqual(0, $petty->balance());
        foreach (MoneyAccount::all() as $m) {
            $this->assertGreaterThanOrEqual(0, $m->balance(), $m->name.' must not be negative in the demo');
        }
    }

    public function test_approval_matrix_multi_level_and_rejection(): void
    {
        // Uttara equipment ৳26,000: GM then Owner
        $req = Requisition::where('type', 'EQUIPMENT')->first();
        $this->api('E-1002');
        $step = collect($this->getJson('/api/approvals')->json())->firstWhere('requisition_id', $req->id);
        $this->postJson('/api/approvals/'.$step['id'], ['approve' => true])->assertOk();
        $this->assertSame('PENDING_APPROVAL', $req->fresh()->status);
        $this->api('E-1001');
        $step = collect($this->getJson('/api/approvals')->json())->firstWhere('requisition_id', $req->id);
        $this->postJson('/api/approvals/'.$step['id'], ['approve' => false])->assertStatus(422); // reason required
        $this->postJson('/api/approvals/'.$step['id'], ['approve' => false, 'comment' => 'Not this month'])->assertOk();
        $this->assertSame('REJECTED', $req->fresh()->status);
        // worker cannot act
        $this->api('E-2008');
        $this->assertCount(0, $this->getJson('/api/approvals')->json());
    }

    public function test_tasks_flow_with_proof_review_rework_and_kpi(): void
    {
        $this->api('E-2004');
        $this->postJson('/api/tasks', ['title' => 'Clean hood', 'due_at' => now()->addHour(), 'proof_required' => true, 'assignee_ids' => [$this->u('E-2008')->id]])->assertOk();
        $this->api('E-2008');
        $t = collect($this->getJson('/api/tasks')->json())->firstWhere('title', 'Clean hood');
        $this->postJson("/api/tasks/{$t['id']}/start")->assertOk();
        $this->postJson("/api/tasks/{$t['id']}/complete")->assertStatus(422); // photo required
        $this->post("/api/tasks/{$t['id']}/complete", ['photo' => UploadedFile::fake()->image('p.jpg'), 'note' => 'done'], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('status', 'SUBMITTED');
        $this->api('E-2004');
        $this->postJson("/api/tasks/{$t['id']}/review", ['approve' => false, 'note' => 'still greasy'])->assertOk()->assertJsonPath('rework_count', 1);
        $this->api('E-2008');
        $this->post("/api/tasks/{$t['id']}/complete", ['photo' => UploadedFile::fake()->image('p2.jpg')], ['Accept' => 'application/json'])->assertOk();
        $this->api('E-2004');
        $this->postJson("/api/tasks/{$t['id']}/review", ['approve' => true])->assertOk()->assertJsonPath('status', 'DONE')->assertJsonPath('on_time', true);
        $this->api('E-2008');
        $kpi = $this->getJson('/api/kpi')->assertOk()->json();
        $this->assertNotNull($kpi['score']);
        $this->assertGreaterThan(0, $kpi['tasks_total']);
        $this->api('E-2004');
        $this->assertNotEmpty($this->getJson('/api/kpi/team')->json());
    }

    public function test_recurring_checklist_and_overdue_escalation(): void
    {
        $this->assertTrue(\App\Models\Task::whereNotNull('template_id')->whereDate('due_at', today())->exists(), 'checklists generated today');
        $t = \App\Models\Task::create(['title' => 'Late one', 'assigned_by' => $this->u('E-2004')->id, 'assignee_id' => $this->u('E-2008')->id, 'location_id' => 5, 'due_at' => now()->subHour()]);
        $this->assertGreaterThanOrEqual(1, \App\Services\TaskService::escalateOverdue());
        $this->assertTrue($t->fresh()->escalated);
        $this->assertTrue(\App\Models\AppNotification::where('user_id', $this->u('E-2004')->id)->where('title', 'Overdue task')->exists());
    }

    public function test_standing_orders_and_cutoff(): void
    {
        \App\Models\Requisition::where('source', 'STANDING')->where('status', 'DRAFT')->delete();
        $n = \App\Services\RequisitionService::generateStanding();
        $this->assertGreaterThanOrEqual(3, $n);
        $draft = Requisition::where('source', 'STANDING')->where('status', 'DRAFT')->where('from_location_id', Location::where('code', 'BR-GUL')->value('id'))->first();
        $this->api('E-2001');
        $line = $draft->lines->first();
        $this->postJson("/api/requisitions/{$draft->id}/update-draft", ['qtys' => [$line->id => 99]])->assertOk();
        $this->assertEquals(99, $line->fresh()->qty_requested);
        \App\Services\RequisitionService::submitDueStanding(true);
        $this->assertSame('SENT_TO_FULFILLER', $draft->fresh()->status, 'locked & sent at cut-off');
        $this->postJson("/api/requisitions/{$draft->id}/update-draft", ['qtys' => [$line->id => 5]])->assertStatus(422);
    }

    public function test_pos_sale_deducts_stock_posts_vat_and_cash_close(): void
    {
        $cashier = $this->u('E-2011');
        $this->actingAs($cashier);
        $this->post(route('pos.open'), ['opening_cash' => 2000])->assertSessionHas('ok');
        $gul = Location::where('code', 'BR-GUL')->value('id');
        $base = StockService::qty($gul, $this->item('SF-001'));
        $vatBefore = (float) JournalLine::where('account_id', \App\Services\LedgerService::id('2300'))->sum('credit');
        $o = $this->postJson(route('pos.store'), ['order_type' => 'DINE_IN', 'lines' => [['item_id' => $this->item('MN-001'), 'qty' => 2]]])->assertOk()->json('order');
        $this->assertEquals(672, $o['total']); // 640 + 5% VAT
        $this->postJson(route('pos.store'), ['order_type' => 'TAKEAWAY', 'discount' => 100, 'lines' => [['item_id' => $this->item('MN-001'), 'qty' => 2]]])->assertStatus(422); // >5% limit
        $cash = PaymentMethod::where('name', 'Cash')->value('id');
        $bkash = PaymentMethod::where('name', 'bKash')->value('id');
        $this->postJson(route('pos.pay', $o['id']), ['payments' => [['payment_method_id' => $bkash, 'amount' => 672]]])->assertStatus(422); // TxID needed
        $this->postJson(route('pos.pay', $o['id']), ['payments' => [['payment_method_id' => $cash, 'amount' => 700]]])->assertOk();
        $this->assertEqualsWithDelta($base - 0.7, StockService::qty($gul, $this->item('SF-001')), 0.001, 'recipe deduction');
        $this->assertEqualsWithDelta($vatBefore + 32, (float) JournalLine::where('account_id', \App\Services\LedgerService::id('2300'))->sum('credit'), 0.01);
        $this->post(route('pos.close'), ['counted_cash' => 2672])->assertSessionHas('ok');
        $this->assertLedgerBalanced();
        // manager voids an open order; cashier cannot
        $this->post(route('pos.open'), ['opening_cash' => 0]);
        $o2 = $this->postJson(route('pos.store'), ['order_type' => 'TAKEAWAY', 'lines' => [['item_id' => $this->item('MN-006'), 'qty' => 1]]])->json('order');
        $this->postJson(route('pos.void', $o2['id']), ['reason' => 'x'])->assertForbidden();
        $this->actingAs($this->u('E-2001'))->postJson(route('pos.void', $o2['id']), ['reason' => 'customer left'])->assertOk();
    }

    public function test_catering_confirm_creates_orders_tasks_invoice_and_payment(): void
    {
        $ev = \App\Models\CateringEvent::where('status', 'INQUIRY')->first();
        $this->actingAs($this->u('E-1070'));
        $this->post(route('cat.action', [$ev, 'confirm']))->assertSessionHas('ok');
        $this->assertSame('CONFIRMED', $ev->fresh()->status);
        $this->assertSame(2, Requisition::where('catering_event_id', $ev->id)->count());
        $bank = MoneyAccount::where('type', 'BANK')->first();
        $this->post(route('cat.action', [$ev, 'payment']), ['amount' => 999999, 'money_account_id' => $bank->id])->assertSessionHas('error');
        $this->post(route('cat.action', [$ev, 'payment']), ['amount' => 20000, 'money_account_id' => $bank->id])->assertSessionHas('ok');
        $this->assertEquals(20000, $ev->fresh()->paid);
        $this->assertLedgerBalanced();
    }

    public function test_payroll_from_attendance_with_approval_chain_and_payslip_on_mobile(): void
    {
        $this->setting('attendance.test_mode', 1);
        $hr = $this->u('E-1020');
        $this->actingAs($hr);
        $month = now()->format('Y-m');
        $this->post(route('payroll.generate'), ['month' => $month])->assertRedirect();
        $run = PayrollRun::where('month', $month)->first();
        $karim = $run->payslips()->where('user_id', $this->u('E-2005')->id)->first();
        $this->assertEquals(1000, $karim->advance_deduction, 'salary advance instalment recovered');
        $this->assertGreaterThan(0, $karim->present_days);
        $this->assertEqualsWithDelta($karim->gross - $karim->total_deduction, $karim->net, 0.01);
        $this->post(route('payroll.action', [$run, 'submit']))->assertRedirect();
        $this->approveAllAs();
        $this->assertSame('APPROVED', $run->fresh()->status);
        $this->actingAs($this->u('E-1010'))->post(route('payroll.action', [$run, 'pay']))->assertRedirect();
        $this->assertSame('PAID', $run->fresh()->status);
        $this->assertLedgerBalanced();
        $this->api('E-2005');
        $slips = $this->getJson('/api/payslips')->assertOk()->json();
        $this->assertGreaterThanOrEqual(2, count($slips));
    }

    public function test_leave_request_approval_shows_on_attendance(): void
    {
        $this->api('E-2008');
        $d = today()->addDays(3)->toDateString();
        $this->postJson('/api/leave', ['leave_type_id' => \App\Models\LeaveType::where('name', 'Casual Leave')->value('id'), 'from_date' => $d, 'to_date' => $d, 'reason' => 'family'])->assertOk();
        $this->postJson('/api/leave', ['leave_type_id' => \App\Models\LeaveType::where('name', 'Casual Leave')->value('id'), 'from_date' => $d, 'to_date' => today()->addDays(40)->toDateString()])->assertStatus(422); // balance
        $this->approveAllAs();
        $this->assertTrue(\App\Services\AttendanceService::onLeave($this->u('E-2008')->id, $d));
    }

    public function test_api_login_me_home_for_every_demo_role(): void
    {
        foreach (WebPagesTest::ROLES as $code) {
            $res = $this->postJson('/api/login', ['login' => $code, 'password' => '123456', 'device_id' => 'dev-'.$code])->assertOk();
            $token = $res->json('token');
            $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/me')->assertOk()->assertJsonPath('employee_code', $code);
            $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/home')->assertOk()->assertJsonStructure(['attendance', 'tasks', 'counts']);
            $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/notifications')->assertOk();
            app('auth')->forgetGuards();
        }
        $this->postJson('/api/login', ['login' => 'E-2008', 'password' => 'bad'])->assertStatus(422);
        $this->api('E-1001');
        $this->getJson('/api/dashboard')->assertOk()->assertJsonStructure(['attendance', 'approvals', 'sales', 'money']);
        $this->api('E-2008');
        $this->getJson('/api/dashboard')->assertForbidden();
    }
}
