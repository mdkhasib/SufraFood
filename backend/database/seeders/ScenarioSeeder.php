<?php

namespace Database\Seeders;

use App\Models\ApprovalStep;
use App\Models\CateringEvent;
use App\Models\Expense;
use App\Models\Item;
use App\Models\Location;
use App\Models\MoneyAccount;
use App\Models\PaymentMethod;
use App\Models\PayrollRun;
use App\Models\Requisition;
use App\Models\Task;
use App\Models\User;
use App\Models\Vendor;
use App\Services\ApprovalService;
use App\Services\AttendanceService;
use App\Services\CateringService;
use App\Services\LedgerService;
use App\Services\PayrollService;
use App\Services\PosService;
use App\Services\ProductionService;
use App\Services\PurchaseService;
use App\Services\RequisitionService;
use App\Services\StockService;
use App\Services\TaskService;
use App\Support\Features;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class ScenarioSeeder extends Seeder
{
    protected function u(string $code): User
    {
        $u = User::where('employee_code', $code)->firstOrFail();
        auth()->setUser($u);

        return $u;
    }

    protected function item(string $code): int
    {
        return Item::where('code', $code)->value('id');
    }

    protected function line(Requisition $r, string $code): int
    {
        return $r->lines()->where('item_id', $this->item($code))->value('id');
    }

    /** Approve every pending step of a model, acting as whoever can. */
    protected function approveAll($model): void
    {
        for ($i = 0; $i < 5; $i++) {
            $step = ApprovalStep::where('approvable_type', $model->getMorphClass())->where('approvable_id', $model->id)->where('status', 'PENDING')->first();
            if (! $step) {
                return;
            }
            $approver = ApprovalService::approversFor($step)->first();
            auth()->setUser($approver);
            ApprovalService::act($step, $approver, true, 'OK');
        }
    }

    protected function image(string $path, string $title, array $lines): string
    {
        $img = imagecreatetruecolor(480, 360);
        imagefill($img, 0, 0, imagecolorallocate($img, 250, 248, 240));
        $ink = imagecolorallocate($img, 40, 40, 40);
        imagestring($img, 5, 20, 20, $title, $ink);
        $y = 60;
        foreach ($lines as $l) {
            imagestring($img, 4, 20, $y, $l, $ink);
            $y += 26;
        }
        ob_start();
        imagejpeg($img, null, 80);
        Storage::disk('public')->put($path, ob_get_clean());

        return $path;
    }

    public function run(): void
    {
        Features::flush();
        $cash = MoneyAccount::where('name', 'HO Main Cash')->first();
        $brac = MoneyAccount::where('name', 'like', 'BRAC%')->first();
        $pmCash = PaymentMethod::where('name', 'Cash')->value('id');

        // ---------- buyer advances ----------
        $acct = $this->u('E-1010');
        PurchaseService::issueAdvance($acct, User::where('employee_code', 'E-1031')->first(), 10000, $cash->id, 'Weekly market advance');
        PurchaseService::issueAdvance($acct, User::where('employee_code', 'E-1032')->first(), 8000, $cash->id, 'Weekly market advance');

        // ---------- the chain: branch food orders → CK → WH → purchase → back ----------
        $today11 = today()->setTime(11, 0);
        $fo1 = RequisitionService::create($this->u('E-2001'), ['type' => 'FOOD_ORDER', 'needed_by' => $today11, 'lines' => [['item_id' => $this->item('SF-001'), 'qty' => 20], ['item_id' => $this->item('SF-006'), 'qty' => 20]], 'notes' => 'Friday rush'], true, 'STANDING');
        $fo2 = RequisitionService::create($this->u('E-6001'), ['type' => 'FOOD_ORDER', 'needed_by' => $today11, 'lines' => [['item_id' => $this->item('SF-001'), 'qty' => 25]]], true, 'STANDING');
        $chef = $this->u('E-1050');
        RequisitionService::accept($fo1, $chef);
        RequisitionService::accept($fo2, $chef);
        ProductionService::createOrders(today(), $chef, User::where('employee_code', 'E-1051')->value('id'));
        $rm = ProductionService::requestRawMaterial(today(), $chef);

        $whm = $this->u('E-1040');
        RequisitionService::accept($rm, $whm, [$this->line($rm, 'RM-002') => StockService::qty(Location::where('code', 'WH')->value('id'), $this->item('RM-002'))], 'Chicken short — purchase raised');
        $pr = RequisitionService::raiseShortagePurchase($rm->fresh(), $whm);
        $this->approveAll($pr);
        $pa = PurchaseService::assign($pr->fresh(), $this->u('E-1030'), ['buyer_id' => User::where('employee_code', 'E-1031')->value('id'), 'deadline_at' => now()->addHour(), 'funding' => 'CASH_ADVANCE', 'payment_method_id' => $pmCash]);
        $rahim = $this->u('E-1031');
        PurchaseService::accept($pa, $rahim);
        $receipt = $this->image('receipts/demo-chicken.jpg', 'AMIN POULTRY - Kawran Bazar', ['Broiler chicken  8 kg x 215', 'Total: Tk 1,720', now()->format('d M Y h:i A'), 'Paid: cash']);
        PurchaseService::submitReceipt($pa, $rahim, [$this->line($pr, 'RM-002') => ['qty' => 8, 'unit_price' => 215]], $receipt, 'Market price slightly up', Vendor::where('name', 'like', 'Amin%')->value('id'));
        PurchaseService::markDelivered($pa->fresh(), $rahim);
        RequisitionService::receive($pr->fresh(), $this->u('E-1041'));
        $rm = $rm->fresh('lines');
        RequisitionService::dispatch($rm, $this->u('E-1041'), $rm->lines->mapWithKeys(fn ($l) => [$l->id => $l->qty_requested])->all());
        RequisitionService::receive($rm->fresh(), $this->u('E-1050'));
        foreach (\App\Models\ProductionOrder::whereDate('planned_for', today())->get() as $po) {
            ProductionService::complete($po, $this->u('E-1051'));
        }
        $kalam = User::where('employee_code', 'E-1060')->first();
        RequisitionService::dispatch($fo1->fresh(), $this->u('E-1050'), [], $kalam->id, 'Van 1 · DM-NA-11-2233');
        $fo1->fresh()->dispatch->update(['status' => 'DELIVERED', 'picked_up_at' => now(), 'delivered_at' => now()]);
        RequisitionService::receive($fo1->fresh(), $this->u('E-2018'));
        RequisitionService::dispatch($fo2->fresh(), $this->u('E-1050'), [], $kalam->id, 'Van 1 · DM-NA-11-2233'); // left for the driver demo

        // ---------- open items for each demo login ----------
        $tomorrow = today()->addDay()->setTime(11, 0);
        RequisitionService::create($this->u('E-3001'), ['type' => 'FOOD_ORDER', 'needed_by' => $tomorrow, 'lines' => [['item_id' => $this->item('SF-003'), 'qty' => 8], ['item_id' => $this->item('SF-007'), 'qty' => 6]], 'notes' => 'Birthday booking tomorrow']);
        RequisitionService::generateStanding();
        RequisitionService::create($this->u('E-2001'), ['type' => 'SUPPLIES', 'needed_by' => today()->addDay()->setTime(10, 0), 'lines' => [['item_id' => $this->item('CS-001'), 'qty' => 300], ['item_id' => $this->item('CS-003'), 'qty' => 50]], 'notes' => 'Takeaway boxes running low']);
        RequisitionService::create($this->u('E-2001'), ['type' => 'SUPPLIES', 'needed_by' => today()->addDay()->setTime(10, 0), 'lines' => [['item_id' => $this->item('CS-004'), 'qty' => 4]]]);
        RequisitionService::create($this->u('E-4001'), ['type' => 'EQUIPMENT', 'needed_by' => today()->addDays(5), 'lines' => [['item_id' => $this->item('AS-001'), 'qty' => 4]], 'notes' => 'New buffet counter']);
        RequisitionService::create($this->u('E-2001'), ['type' => 'REPAIR', 'needed_by' => today()->addDays(2), 'lines' => [['description' => 'Freezer #2 door gasket broken', 'qty' => 1]]]);
        RequisitionService::create($this->u('E-1040'), ['type' => 'PURCHASE', 'needed_by' => today()->addDay()->setTime(7, 0), 'lines' => [['item_id' => $this->item('RM-004'), 'qty' => 50]], 'notes' => 'Weekly onion']);
        $pr2 = RequisitionService::create($this->u('E-1040'), ['type' => 'PURCHASE', 'needed_by' => now()->addHours(3), 'lines' => [['item_id' => $this->item('RM-015'), 'qty' => 5], ['item_id' => $this->item('RM-018'), 'qty' => 10]], 'notes' => 'Urgent for lunch']);
        $this->approveAll($pr2);
        PurchaseService::assign($pr2->fresh(), $this->u('E-1030'), ['buyer_id' => $rahim->id, 'deadline_at' => now()->addHours(2), 'funding' => 'CASH_ADVANCE', 'payment_method_id' => $pmCash]);

        // vendor credit purchase (rice) fully received → open vendor bill
        $pr3 = RequisitionService::create($this->u('E-1040'), ['type' => 'PURCHASE', 'needed_by' => now()->addDay(), 'lines' => [['item_id' => $this->item('RM-001'), 'qty' => 340]], 'notes' => 'Monthly rice']);
        $this->approveAll($pr3);
        $jalal = User::where('employee_code', 'E-1032')->first();
        $pa3 = PurchaseService::assign($pr3->fresh(), $this->u('E-1030'), ['buyer_id' => $jalal->id, 'deadline_at' => now()->addDay(), 'funding' => 'VENDOR_CREDIT', 'vendor_id' => Vendor::where('name', 'like', 'Karim Rice%')->value('id')]);
        $this->u('E-1032');
        PurchaseService::accept($pa3, $jalal);
        PurchaseService::submitReceipt($pa3, $jalal, [], $this->image('receipts/demo-rice.jpg', 'KARIM RICE AGENCY', ['Chinigura rice 340 kg x 140', 'Total: Tk 47,600', 'Terms: 15 days credit']), 'On credit');
        PurchaseService::markDelivered($pa3->fresh(), $jalal);
        RequisitionService::receive($pr3->fresh(), $this->u('E-1042'));

        // own-money refund claim (pending approval)
        PurchaseService::createClaim($this->u('E-1031'), 1200, 'Gas cylinder refill paid from own pocket (CK)', $this->image('receipts/demo-gas.jpg', 'DHAKA GAS SUPPLY', ['Cylinder refill', 'Tk 1,200', 'Paid: own money']));

        // ---------- HR items ----------
        AttendanceService::requestCorrection($this->u('E-2005'), today()->subDays(3)->toDateString(), '08:02', '16:10', 'Forgot to check in — phone battery died');
        $belal = $this->u('E-2006');
        $leave = \App\Models\LeaveRequest::create(['user_id' => $belal->id, 'leave_type_id' => \App\Models\LeaveType::where('name', 'Casual Leave')->value('id'), 'from_date' => today()->addDays(4), 'to_date' => today()->addDays(5), 'days' => 2, 'reason' => 'Sister\'s wedding']);
        ApprovalService::start($leave, $belal);
        $adv = PayrollService::requestAdvance($this->u('E-2005'), 3000, 3, 'Medical bill');
        $this->approveAll($adv);
        PayrollService::payAdvance($adv->fresh(), $this->u('E-1010'), $cash->id);

        // payroll for last month: generate → approve chain → pay
        $hr = $this->u('E-1020');
        $run = PayrollService::generate(now()->subMonthNoOverflow()->format('Y-m'), $hr);
        PayrollService::submit($run, $hr);
        $this->approveAll($run->fresh());
        PayrollService::pay($run->fresh(), $this->u('E-1010'));

        // ---------- tasks today ----------
        TaskService::generateRecurring();
        $due = fn ($h, $m = 0) => today()->setTime($h, $m);
        TaskService::create($this->u('E-2004'), ['title' => 'Cut onions 5 kg', 'title_bn' => '৫ কেজি পেঁয়াজ কাটা', 'due_at' => $due(11, 30)], [User::where('employee_code', 'E-2008')->value('id')]);
        TaskService::create($this->u('E-2004'), ['title' => 'Prepare salad for lunch', 'title_bn' => 'দুপুরের সালাদ', 'due_at' => $due(12), 'proof_required' => true], [User::where('employee_code', 'E-2008')->value('id'), User::where('employee_code', 'E-2005')->value('id')]);
        TaskService::create($this->u('E-2003'), ['title' => 'Marinate tikka 8 kg', 'title_bn' => '৮ কেজি টিক্কা মেরিনেট', 'due_at' => $due(12)], [User::where('employee_code', 'E-2010')->value('id')]);
        TaskService::create($this->u('E-2001'), ['title' => 'Deep clean freezer #2', 'title_bn' => 'ফ্রিজার #২ পরিষ্কার', 'due_at' => $due(17), 'proof_required' => true], [User::where('employee_code', 'E-2004')->value('id')]);
        TaskService::create($this->u('E-1002'), ['title' => 'Send AC servicing quotation', 'title_bn' => 'এসি সার্ভিসিং কোটেশন', 'due_at' => today()->addDay()->setTime(17, 0)], [User::where('employee_code', 'E-2001')->value('id')]);
        $grill = TaskService::create($this->u('E-2004'), ['title' => 'Clean tandoor grill', 'title_bn' => 'তন্দুর গ্রিল পরিষ্কার', 'due_at' => $due(9, 30), 'proof_required' => true], [User::where('employee_code', 'E-2010')->value('id')])[0];
        $grill->update(['status' => 'SUBMITTED', 'started_at' => $due(8, 40), 'submitted_at' => $due(9, 10), 'proof_note' => 'Done, see photo', 'proof_photo' => $this->image('proofs/demo-grill.jpg', 'Grill cleaned', ['Tandoor grill', 'Sufra Gulshan', 'by Sumon Ali'])]);
        Task::where('title', 'Mop dining floor')->whereDate('due_at', today())->update(['due_at' => $due(9, 30)]);

        // ---------- catering ----------
        $cm = $this->u('E-1070');
        $ev = CateringEvent::create(['number' => 'CE-'.now()->format('ym').'-007', 'title' => 'Wedding reception (Hasan family)', 'client_name' => 'Mr. Mahmudul Hasan', 'client_phone' => '01811000222', 'event_at' => today()->addDays(8)->setTime(19, 0), 'venue' => 'Mohammadpur Community Centre', 'lat' => 23.7590, 'lng' => 90.3590, 'radius_m' => 100, 'guests' => 300, 'package_name' => 'Royal Kacchi Package', 'price_per_head' => 650, 'total' => 195000, 'status' => 'INQUIRY', 'manager_id' => $cm->id]);
        foreach ([['SF-003', 105, 'FOOD'], ['SF-006', 120, 'FOOD'], ['SF-008', 300, 'FOOD'], ['AS-001', 12, 'EQUIPMENT'], ['AS-002', 330, 'EQUIPMENT'], ['AS-003', 330, 'EQUIPMENT']] as [$c, $q, $k]) {
            $ev->items()->create(['item_id' => $this->item($c), 'qty' => $q, 'kind' => $k]);
        }
        $ev->staff()->attach(User::whereIn('employee_code', ['E-1071', 'E-1072', 'E-1073', 'E-1074', 'E-1075'])->pluck('id'), ['duty' => 'Service']);
        CateringService::confirm($ev, $cm);
        CateringService::recordPayment($ev->fresh(), $cm, $brac->id, 97500, 'Advance 50% (bank)');
        $ev2 = CateringEvent::create(['number' => 'CE-'.now()->format('ym').'-008', 'title' => 'Corporate lunch (BetaSoft Ltd)', 'client_name' => 'BetaSoft Ltd', 'client_phone' => '01911000333', 'event_at' => today()->addDays(3)->setTime(13, 0), 'venue' => 'BetaSoft office, Banani', 'lat' => 23.7940, 'lng' => 90.4040, 'guests' => 120, 'package_name' => 'Biryani Lunch Box', 'price_per_head' => 380, 'total' => 45600, 'status' => 'INQUIRY', 'manager_id' => $cm->id]);
        foreach ([['SF-001', 42, 'FOOD'], ['SF-006', 30, 'FOOD'], ['CS-001', 130, 'EQUIPMENT']] as [$c, $q, $k]) {
            $ev2->items()->create(['item_id' => $this->item($c), 'qty' => $q, 'kind' => $k]);
        }

        // ---------- POS sales (each branch) ----------
        mt_srand(7);
        $menu = Item::where('type', 'MENU')->get();
        $methods = PaymentMethod::where('active', true)->get()->keyBy('name');
        foreach (['E-2011' => 'BR-GUL', 'E-3004' => 'BR-DHN', 'E-4004' => 'BR-UTT', 'E-5004' => 'BR-MIR', 'E-6004' => 'BR-BAN', 'E-7004' => 'BR-BAS'] as $cashierCode => $loc) {
            $cashier = $this->u($cashierCode);
            $shift = PosService::openShift($cashier, 3000);
            $n = mt_rand(8, 14);
            for ($i = 0; $i < $n; $i++) {
                $lines = [];
                foreach ($menu->random(mt_rand(1, 4)) as $m) {
                    $lines[] = ['item_id' => $m->id, 'qty' => mt_rand(1, 3)];
                }
                $o = PosService::createOrder($cashier, ['order_type' => ['DINE_IN', 'DINE_IN', 'TAKEAWAY', 'DELIVERY'][mt_rand(0, 3)], 'lines' => $lines, 'discount' => mt_rand(1, 10) === 1 ? floor(collect($lines)->sum(fn ($l) => $menu->firstWhere('id', $l['item_id'])->sale_price * $l['qty']) * 0.05) : 0]);
                $pick = mt_rand(1, 10);
                $pm = $pick <= 4 ? 'Cash' : ($pick <= 6 ? 'Card' : ($pick <= 9 ? 'bKash' : 'Nagad'));
                $amt = $pm === 'Cash' ? ceil($o->total / 100) * 100 : $o->total;
                PosService::pay($o, $cashier, [['payment_method_id' => $methods[$pm]->id, 'amount' => $amt, 'reference' => $pm === 'Cash' || $pm === 'Card' ? null : 'TX'.mt_rand(100000, 999999)]]);
            }
            $expected = $shift->opening_cash + (float) \App\Models\PosPayment::whereHas('order', fn ($q) => $q->where('cashier_shift_id', $shift->id))->whereHas('paymentMethod', fn ($q) => $q->where('type', 'CASH'))->sum('amount');
            PosService::closeShift($shift->fresh(), $cashier, $loc === 'BR-GUL' ? $expected - 120 : $expected);
        }

        // ---------- expenses & wastage ----------
        $acc = $this->u('E-1010');
        foreach (Location::where('type', 'BRANCH')->get() as $b) {
            foreach ([['6100', 120000, 'Shop rent'], ['6200', 18000, 'Electricity & water'], ['6300', 9000, 'Gas']] as [$code, $amt, $note]) {
                $je = LedgerService::post($note.' – '.$b->name, [['code' => $code, 'debit' => $amt, 'location_id' => $b->id], LedgerService::moneyLine($brac, 0, $amt)], null, $b->id, now()->subMonthNoOverflow()->endOfMonth());
                Expense::create(['date' => now()->subMonthNoOverflow()->endOfMonth(), 'account_id' => LedgerService::id($code), 'money_account_id' => $brac->id, 'location_id' => $b->id, 'amount' => $amt, 'note' => $note, 'created_by' => $acc->id, 'journal_entry_id' => $je?->id]);
            }
        }
        $gul = Location::where('code', 'BR-GUL')->value('id');
        $this->u('E-2018');
        $mv = StockService::move($gul, $this->item('SF-001'), -1.5, 'WASTAGE', null, null, 'Spoiled — fridge door left open');
        LedgerService::post('Wastage Chicken Biryani Base 1.5 kg', [['code' => '5100', 'debit' => round(1.5 * $mv->unit_cost, 2), 'location_id' => $gul], ['code' => '1200', 'credit' => round(1.5 * $mv->unit_cost, 2), 'location_id' => $gul]], $mv, $gul);

        auth()->forgetUser();
    }
}
