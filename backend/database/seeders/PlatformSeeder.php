<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\ApprovalRule;
use App\Models\Department;
use App\Models\DiningTable;
use App\Models\Feature;
use App\Models\Holiday;
use App\Models\Item;
use App\Models\LeaveType;
use App\Models\Location;
use App\Models\MoneyAccount;
use App\Models\PaymentMethod;
use App\Models\Recipe;
use App\Models\Role;
use App\Models\Setting;
use App\Models\ShiftTemplate;
use App\Models\StandingOrder;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Models\Vendor;
use App\Services\AttendanceService;
use App\Services\LedgerService;
use App\Services\StockService;
use App\Support\Features;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class PlatformSeeder extends Seeder
{
    public const PASSWORD = '123456';

    public static array $u = [];   // employee_code => User

    public static array $loc = []; // code => Location

    protected static ?string $hash = null;

    public static function hash(): string
    {
        return self::$hash ??= Hash::make(self::PASSWORD);
    }

    public function run(): void
    {
        // ---------- features (demo environment: everything ON) ----------
        foreach (config('groupops.features') as $key => [$module, $name, $deps]) {
            Feature::create(['key' => $key, 'module' => $module, 'name' => $name, 'depends_on' => $deps, 'status' => 'ON']);
        }
        $i = 0;
        foreach (config('groupops.modules') as $name => [$bn, $desc]) {
            \App\Models\FeatureModule::create(['name' => $name, 'name_bn' => $bn, 'description' => $desc, 'status' => 'ON', 'sort' => ++$i, 'admin_access' => false]);
        }
        foreach (config('groupops.settings') as $k => $v) {
            Setting::create(['key' => $k, 'value' => $v]);
        }
        Setting::where('key', 'attendance.test_mode')->update(['value' => 1]); // demo: check-in works anywhere
        Setting::where('key', 'kpi.payroll_link')->delete();
        Features::flush();

        // ---------- roles ----------
        $self = config('groupops.self_permissions');
        foreach (config('groupops.roles') as $key => [$name, $tier, $scope, $perms]) {
            $role = Role::create(['key' => $key, 'name' => $name, 'tier' => $tier, 'scope' => $scope]);
            $list = $perms === '*' ? array_keys(config('groupops.permissions')) : array_merge(array_diff($perms, ['self']), in_array('self', (array) $perms) ? $self : []);
            foreach (array_unique($list) as $p) {
                DB::table('role_permissions')->insert(['role_id' => $role->id, 'permission' => $p]);
            }
        }

        // ---------- departments ----------
        $deps = [];
        foreach (['Management' => 'ব্যবস্থাপনা', 'HR' => 'মানবসম্পদ', 'Accounts' => 'হিসাব', 'Purchase' => 'ক্রয়', 'Warehouse' => 'গুদাম', 'Central Kitchen' => 'কেন্দ্রীয় রান্নাঘর', 'Catering' => 'ক্যাটারিং', 'FOH' => 'ফ্রন্ট', 'Kitchen' => 'রান্নাঘর', 'Store' => 'স্টোর', 'Security & Cleaning' => 'নিরাপত্তা ও পরিচ্ছন্নতা', 'Dispatch' => 'ডেলিভারি'] as $n => $bn) {
            $deps[$n] = Department::create(['name' => $n, 'name_bn' => $bn])->id;
        }

        // ---------- locations ----------
        $locs = [
            ['HO', 'Head Office', 'প্রধান কার্যালয়', 'OFFICE', 'Tejgaon', 23.7639, 90.3925, 80],
            ['WH', 'Central Warehouse', 'কেন্দ্রীয় গুদাম', 'WAREHOUSE', 'Tejgaon I/A', 23.7600, 90.3990, 120],
            ['CK', 'Central Kitchen', 'কেন্দ্রীয় রান্নাঘর', 'CENTRAL_KITCHEN', 'Tejgaon I/A', 23.7612, 90.4004, 100],
            ['CAT', 'Sufra Catering', 'সুফরা ক্যাটারিং', 'CATERING', 'Mohakhali', 23.7780, 90.4050, 80],
            ['BR-GUL', 'Sufra Gulshan', 'সুফরা গুলশান', 'BRANCH', 'Gulshan-2', 23.7940, 90.4143, 60],
            ['BR-DHN', 'Sufra Dhanmondi', 'সুফরা ধানমন্ডি', 'BRANCH', 'Dhanmondi 27', 23.7550, 90.3760, 60],
            ['BR-UTT', 'Sufra Uttara', 'সুফরা উত্তরা', 'BRANCH', 'Uttara Sec-7', 23.8690, 90.3980, 60],
            ['BR-MIR', 'Sufra Mirpur', 'সুফরা মিরপুর', 'BRANCH', 'Mirpur-10', 23.8070, 90.3685, 60],
            ['BR-BAN', 'Sufra Banani', 'সুফরা বনানী', 'BRANCH', 'Banani-11', 23.7937, 90.4066, 60],
            ['BR-BAS', 'Sufra Bashundhara', 'সুফরা বসুন্ধরা', 'BRANCH', 'Bashundhara R/A', 23.8150, 90.4270, 60],
        ];
        foreach ($locs as $i => [$code, $name, $bn, $type, $addr, $lat, $lng, $rad]) {
            self::$loc[$code] = Location::create(['code' => $code, 'name' => $name, 'name_bn' => $bn, 'type' => $type, 'address' => $addr, 'phone' => $type === 'BRANCH' ? sprintf('01800-0000%02d', $i) : null, 'lat' => $lat, 'lng' => $lng, 'radius_m' => $rad]);
        }

        // ---------- people ----------
        $phone = 1;
        $mk = function (string $code, string $name, string $bn, string $role, string $loc, ?string $mgr, string $pos, string $dep, float $gross, array $extra = []) use (&$phone, $deps) {
            $basic = round($gross * 0.6);
            $u = User::create(array_merge([
                'employee_code' => $code, 'name' => $name, 'name_bn' => $bn, 'phone' => '017'.str_pad((string) $phone++, 8, '0', STR_PAD_LEFT),
                'password' => self::hash(), 'role_id' => Role::where('key', $role)->value('id'), 'location_id' => self::$loc[$loc]->id,
                'department_id' => $deps[$dep] ?? null, 'manager_id' => $mgr ? self::$u[$mgr]->id : null, 'position' => $pos,
                'joined_at' => now()->subMonths(rand(4, 40))->startOfMonth(),
                'basic_salary' => $basic, 'house_rent' => round($gross * 0.3), 'medical' => round($gross * 0.06), 'conveyance' => $gross - $basic - round($gross * 0.3) - round($gross * 0.06),
                'payout_method' => ['MFS', 'BANK', 'CASH', 'MFS'][$phone % 4], 'payout_account' => '01'.rand(3, 9).rand(10000000, 99999999),
            ], $extra));
            self::$u[$code] = $u;

            return $u;
        };

        User::create(['employee_code' => 'SA-0001', 'name' => 'Super Admin', 'name_bn' => 'সুপার অ্যাডমিন', 'phone' => '01700000000', 'password' => self::hash(), 'is_super_admin' => true, 'location_id' => self::$loc['HO']->id, 'position' => 'System Owner', 'role_id' => Role::where('key', 'owner')->value('id')]);

        // head office
        $mk('E-1001', 'Anwar Hossain', 'আনোয়ার হোসেন', 'owner', 'HO', null, 'Managing Director (MD)', 'Management', 0, ['discount_limit' => 100, 'payout_method' => 'BANK']);
        $mk('E-1002', 'Farhana Islam', 'ফারহানা ইসলাম', 'gm', 'HO', 'E-1001', 'General Manager', 'Management', 180000, ['is_company_admin' => true, 'approval_limit' => 200000, 'discount_limit' => 30]);
        $mk('E-1004', 'Tanvir Ahmed', 'তানভীর আহমেদ', 'admin_officer', 'HO', 'E-1002', 'Admin & IT Officer', 'Management', 45000, ['is_company_admin' => true]);
        $mk('E-1010', 'Mahbub Alam', 'মাহবুব আলম', 'accounts_manager', 'HO', 'E-1002', 'Accounts Manager', 'Accounts', 85000);
        $mk('E-1011', 'Sadia Rahman', 'সাদিয়া রহমান', 'accounts_officer', 'HO', 'E-1010', 'Accounts Officer', 'Accounts', 38000);
        $mk('E-1020', 'Nusrat Jahan', 'নুসরাত জাহান', 'hr_manager', 'HO', 'E-1002', 'HR Manager', 'HR', 80000);
        $mk('E-1021', 'Imran Hossain', 'ইমরান হোসেন', 'hr_officer', 'HO', 'E-1020', 'HR Officer', 'HR', 35000);
        $mk('E-1030', 'Kamrul Hasan', 'কামরুল হাসান', 'purchase_manager', 'HO', 'E-1002', 'Purchase Manager', 'Purchase', 75000, ['approval_limit' => 50000]);
        $mk('E-1031', 'Rahim Uddin', 'রহিম উদ্দিন', 'buyer', 'HO', 'E-1030', 'Buyer', 'Purchase', 22000, ['advance_limit' => 15000, 'own_money_limit' => 3000]);
        $mk('E-1032', 'Jalal Ahmed', 'জালাল আহমেদ', 'buyer', 'HO', 'E-1030', 'Buyer', 'Purchase', 20000, ['advance_limit' => 10000, 'own_money_limit' => 2000]);
        $mk('E-1033', 'Sohel Rana', 'সোহেল রানা', 'buyer', 'HO', 'E-1030', 'Buyer', 'Purchase', 20000, ['advance_limit' => 10000, 'own_money_limit' => 2000]);
        // warehouse
        $mk('E-1040', 'Mizanur Rahman', 'মিজানুর রহমান', 'warehouse_manager', 'WH', 'E-1002', 'Warehouse Manager', 'Warehouse', 60000, ['approval_limit' => 10000]);
        $mk('E-1041', 'Babul Mia', 'বাবুল মিয়া', 'storekeeper', 'WH', 'E-1040', 'Storekeeper', 'Warehouse', 22000);
        $mk('E-1042', 'Shahin Alam', 'শাহীন আলম', 'storekeeper', 'WH', 'E-1040', 'Storekeeper', 'Warehouse', 21000);
        $mk('E-1043', 'Kashem Ali', 'কাশেম আলী', 'worker', 'WH', 'E-1040', 'Loader', 'Warehouse', 15000);
        // central kitchen
        $mk('E-1050', 'Abdul Karim', 'আব্দুল করিম', 'ck_head_chef', 'CK', 'E-1002', 'CK Head Chef', 'Central Kitchen', 90000, ['approval_limit' => 10000]);
        $mk('E-1051', 'Rafiq Islam', 'রফিক ইসলাম', 'supervisor', 'CK', 'E-1050', 'CK Sous Chef', 'Central Kitchen', 45000);
        foreach ([['E-1052', 'Monir Hossain', 'মনির হোসেন', 'CK Helper'], ['E-1053', 'Selim Reza', 'সেলিম রেজা', 'CK Helper'], ['E-1054', 'Faruk Mia', 'ফারুক মিয়া', 'CK Cook'], ['E-1055', 'Jahid Hasan', 'জাহিদ হাসান', 'CK Cook'], ['E-1056', 'Liton Das', 'লিটন দাস', 'CK Cook']] as [$c, $n, $b, $p]) {
            $mk($c, $n, $b, 'worker', 'CK', 'E-1051', $p, 'Central Kitchen', str_contains($p, 'Cook') ? 24000 : 16000);
        }
        $mk('E-1060', 'Abul Kalam', 'আবুল কালাম', 'driver', 'CK', 'E-1050', 'Driver (Van 1)', 'Dispatch', 22000);
        $mk('E-1061', 'Bablu Sheikh', 'বাবলু শেখ', 'driver', 'CK', 'E-1050', 'Driver (Van 2)', 'Dispatch', 22000);
        // catering
        $mk('E-1070', 'Shirin Akter', 'শিরিন আক্তার', 'catering_manager', 'CAT', 'E-1002', 'Catering Manager', 'Catering', 70000, ['approval_limit' => 10000]);
        $mk('E-1071', 'Arif Hossain', 'আরিফ হোসেন', 'supervisor', 'CAT', 'E-1070', 'Catering Supervisor', 'Catering', 35000);
        foreach ([['E-1072', 'Mamun Mia', 'মামুন মিয়া'], ['E-1073', 'Rasel Ahmed', 'রাসেল আহমেদ'], ['E-1074', 'Tuhin Islam', 'তুহিন ইসলাম'], ['E-1075', 'Sabbir Hossain', 'সাব্বির হোসেন']] as [$c, $n, $b]) {
            $mk($c, $n, $b, 'worker', 'CAT', 'E-1071', 'Server', 'Catering', 17000);
        }

        // Sufra Gulshan (full team of 18)
        $mk('E-2001', 'Tanjila Ahmed', 'তানজিলা আহমেদ', 'branch_manager', 'BR-GUL', 'E-1002', 'Branch Manager', 'Management', 65000, ['approval_limit' => 10000, 'discount_limit' => 15]);
        $mk('E-2002', 'Nazmul Hasan', 'নাজমুল হাসান', 'supervisor', 'BR-GUL', 'E-2001', 'Floor Supervisor', 'FOH', 32000);
        $mk('E-2003', 'Jamal Uddin', 'জামাল উদ্দিন', 'head_chef', 'BR-GUL', 'E-2001', 'Branch Head Chef', 'Kitchen', 55000, ['approval_limit' => 5000]);
        $mk('E-2004', 'Shafiq Ahmed', 'শফিক আহমেদ', 'supervisor', 'BR-GUL', 'E-2003', 'Sous Chef / Kitchen Lead', 'Kitchen', 35000);
        $mk('E-2005', 'Karim Mia', 'করিম মিয়া', 'worker', 'BR-GUL', 'E-2004', 'Cook', 'Kitchen', 15000);
        $mk('E-2006', 'Belal Hossain', 'বেলাল হোসেন', 'worker', 'BR-GUL', 'E-2004', 'Cook', 'Kitchen', 15000);
        $mk('E-2007', 'Rubel Mia', 'রুবেল মিয়া', 'worker', 'BR-GUL', 'E-2004', 'Kitchen Helper', 'Kitchen', 12000);
        $mk('E-2008', 'Shanto Das', 'শান্ত দাস', 'worker', 'BR-GUL', 'E-2004', 'Kitchen Helper', 'Kitchen', 12000);
        $mk('E-2009', 'Alamgir Hossain', 'আলমগীর হোসেন', 'worker', 'BR-GUL', 'E-2004', 'Dishwasher', 'Kitchen', 11000);
        $mk('E-2010', 'Sumon Ali', 'সুমন আলী', 'worker', 'BR-GUL', 'E-2004', 'Tandoor Cook', 'Kitchen', 16000);
        $mk('E-2011', 'Mitu Akter', 'মিতু আক্তার', 'cashier', 'BR-GUL', 'E-2002', 'Cashier', 'FOH', 18000, ['discount_limit' => 5]);
        foreach ([['E-2012', 'Rakib Hasan', 'রাকিব হাসান'], ['E-2013', 'Nayeem Islam', 'নাঈম ইসলাম'], ['E-2014', 'Polash Mia', 'পলাশ মিয়া'], ['E-2015', 'Jony Ahmed', 'জনি আহমেদ']] as [$c, $n, $b]) {
            $mk($c, $n, $b, 'worker', 'BR-GUL', 'E-2002', 'Waiter', 'FOH', 13000);
        }
        $mk('E-2016', 'Rina Begum', 'রিনা বেগম', 'worker', 'BR-GUL', 'E-2002', 'Cleaner', 'Security & Cleaning', 10000);
        $mk('E-2017', 'Abul Kashem', 'আবুল কাশেম', 'worker', 'BR-GUL', 'E-2002', 'Security Guard', 'Security & Cleaning', 11000);
        $mk('E-2018', 'Hasan Mahmud', 'হাসান মাহমুদ', 'storekeeper', 'BR-GUL', 'E-2001', 'Branch Storekeeper', 'Store', 18000);

        // other branches: manager, head chef, supervisor, cashier, 5 workers
        $names = [
            'BR-DHN' => ['3', 'Rezaul Karim', 'রেজাউল করিম', ['Nasir Uddin', 'Mohsin Ali', 'Habib Khan', 'Saiful Islam', 'Ripon Mia', 'Nadia Akter', 'Kabir Hossain']],
            'BR-UTT' => ['4', 'Moinul Haque', 'মঈনুল হক', ['Zahid Hasan', 'Shamim Reza', 'Tariq Aziz', 'Masud Rana', 'Hridoy Ahmed', 'Sumi Akter', 'Babu Mia']],
            'BR-MIR' => ['5', 'Habibur Rahman', 'হাবিবুর রহমান', ['Kamal Uddin', 'Sajib Hossain', 'Biplob Das', 'Arman Ali', 'Mithun Roy', 'Shapla Begum', 'Raju Ahmed']],
            'BR-BAN' => ['6', 'Sharmin Sultana', 'শারমিন সুলতানা', ['Mostafa Kamal', 'Imtiaz Ahmed', 'Rony Islam', 'Fahim Hasan', 'Sakib Khan', 'Lima Akter', 'Jewel Rana']],
            'BR-BAS' => ['7', 'Faisal Mahmud', 'ফয়সাল মাহমুদ', ['Golam Rabbani', 'Anis Rahman', 'Sohag Mia', 'Tanvir Hasan', 'Rifat Ahmed', 'Mou Akter', 'Shuvo Das']],
        ];
        foreach ($names as $loc => [$d, $mgrName, $mgrBn, $staff]) {
            $mgr = "E-{$d}001";
            $mk($mgr, $mgrName, $mgrBn, 'branch_manager', $loc, 'E-1002', 'Branch Manager', 'Management', 60000, ['approval_limit' => 10000, 'discount_limit' => 15]);
            $mk("E-{$d}002", $staff[0], '', 'head_chef', $loc, $mgr, 'Branch Head Chef', 'Kitchen', 50000, ['approval_limit' => 5000]);
            $mk("E-{$d}003", $staff[1], '', 'supervisor', $loc, $mgr, 'Floor Supervisor', 'FOH', 30000);
            $mk("E-{$d}004", $staff[5], '', 'cashier', $loc, "E-{$d}003", 'Cashier', 'FOH', 17000, ['discount_limit' => 5]);
            $mk("E-{$d}005", $staff[2], '', 'worker', $loc, "E-{$d}002", 'Cook', 'Kitchen', 15000);
            $mk("E-{$d}006", $staff[3], '', 'worker', $loc, "E-{$d}002", 'Kitchen Helper', 'Kitchen', 12000);
            $mk("E-{$d}007", $staff[4], '', 'worker', $loc, "E-{$d}003", 'Waiter', 'FOH', 13000);
            $mk("E-{$d}008", $staff[6], '', 'worker', $loc, "E-{$d}003", 'Waiter', 'FOH', 13000);
        }

        // location heads
        foreach (['HO' => 'E-1002', 'WH' => 'E-1040', 'CK' => 'E-1050', 'CAT' => 'E-1070', 'BR-GUL' => 'E-2001', 'BR-DHN' => 'E-3001', 'BR-UTT' => 'E-4001', 'BR-MIR' => 'E-5001', 'BR-BAN' => 'E-6001', 'BR-BAS' => 'E-7001'] as $l => $m) {
            self::$loc[$l]->update(['manager_id' => self::$u[$m]->id]);
        }

        $this->teams();

        // ---------- money ----------
        foreach (LedgerService::CHART as [$code, $name, $type]) {
            Account::create(['code' => $code, 'name' => $name, 'type' => $type, 'system' => true]);
        }
        LedgerService::flush();
        $pm = [];
        foreach ([['Cash', 'CASH', true], ['Bank Transfer', 'BANK', true], ['bKash', 'MFS', true], ['Nagad', 'MFS', true], ['Card', 'CARD', true], ['Rocket', 'MFS', false]] as [$n, $t, $a]) {
            $pm[$n] = PaymentMethod::create(['name' => $n, 'type' => $t, 'active' => $a]);
        }
        $acc = fn ($code) => LedgerService::id($code);
        $money = [
            ['HO Main Cash', 'CASH', '1010', $pm['Cash']->id, 'HO', 'E-1010', null, 2000000],
            ['BRAC Bank Current ****4521', 'BANK', '1020', $pm['Bank Transfer']->id, 'HO', null, '****4521', 6000000],
            ['City Bank Current ****7788', 'BANK', '1020', null, 'HO', null, '****7788', 640000],
            ['bKash Merchant ****1122', 'MFS', '1030', $pm['bKash']->id, 'HO', null, '****1122', 4000000],
            ['Nagad Merchant ****3344', 'MFS', '1030', $pm['Nagad']->id, 'HO', null, '****3344', 200000],
            ['Card Clearing (POS)', 'CARD', '1060', $pm['Card']->id, 'HO', null, null, 0],
            ['Petty Cash – Central Kitchen', 'PETTY', '1040', null, 'CK', 'E-1050', null, 10000],
            ['Petty Cash – Warehouse', 'PETTY', '1040', null, 'WH', 'E-1040', null, 5000],
        ];
        foreach (['BR-GUL' => 'E-2001', 'BR-DHN' => 'E-3001', 'BR-UTT' => 'E-4001', 'BR-MIR' => 'E-5001', 'BR-BAN' => 'E-6001', 'BR-BAS' => 'E-7001'] as $l => $m) {
            $money[] = ['Petty Cash – '.self::$loc[$l]->name, 'PETTY', '1040', null, $l, $m, null, 5000];
            $money[] = ['POS Drawer – '.self::$loc[$l]->name, 'DRAWER', '1050', null, $l, $m, null, 3000];
        }
        $openLines = [];
        foreach ($money as [$n, $t, $code, $pmId, $l, $cust, $no, $open]) {
            $ma = MoneyAccount::create(['name' => $n, 'type' => $t, 'account_id' => $acc($code), 'payment_method_id' => $pmId, 'location_id' => self::$loc[$l]->id, 'custodian_id' => $cust ? self::$u[$cust]->id : null, 'account_no' => $no]);
            if ($open > 0) {
                $openLines[] = LedgerService::moneyLine($ma, $open, 0, 'Opening balance');
            }
        }
        $total = array_sum(array_column($openLines, 'debit'));
        $openLines[] = ['code' => '3000', 'credit' => $total];
        LedgerService::post('Opening balances (go-live)', $openLines, null, null, now()->subMonths(2)->startOfMonth(), true);

        // ---------- HR setup ----------
        foreach ([['Casual Leave', 'নৈমিত্তিক ছুটি', 10], ['Sick Leave', 'অসুস্থতাজনিত ছুটি', 14], ['Annual Leave', 'বার্ষিক ছুটি', 16], ['Unpaid Leave', 'বিনা বেতনে ছুটি', 0]] as [$n, $b, $d]) {
            LeaveType::create(['name' => $n, 'name_bn' => $b, 'days_per_year' => $d, 'paid' => $d > 0]);
        }
        $y = now()->year;
        foreach ([["$y-02-21", 'Language Martyrs Day'], ["$y-03-26", 'Independence Day'], ["$y-05-01", 'May Day'], ["$y-12-16", 'Victory Day']] as [$d, $n]) {
            Holiday::create(['date' => $d, 'name' => $n]);
        }
        foreach ([['M', 'Morning', '08:00', '16:00', null, null, 30], ['E', 'Evening', '15:00', '23:00', null, null, 30], ['S', 'Split', '10:00', '15:00', '18:00', '23:00', 0], ['G', 'General', '10:00', '19:00', null, null, 60], ['GD', 'Guard Day', '08:00', '20:00', null, null, 60], ['O', 'Office', '09:00', '18:00', null, null, 60], ['K', 'Kitchen Early', '06:00', '14:00', null, null, 30]] as [$c, $n, $s, $e, $s2, $e2, $b]) {
            ShiftTemplate::create(['code' => $c, 'name' => $n, 'start_time' => $s, 'end_time' => $e, 'start_time2' => $s2, 'end_time2' => $e2, 'break_minutes' => $b]);
        }

        // ---------- approval matrix ----------
        $L = fn ($by, $v = null) => ['by' => $by, 'value' => $v];
        $rules = [
            ['SUPPLIES', 0, 3000, [$L('location_manager')], 120], ['SUPPLIES', 3000.01, null, [$L('location_manager'), $L('role', 'gm')], 240],
            ['EQUIPMENT', 0, 20000, [$L('location_manager'), $L('role', 'gm')], 1440], ['EQUIPMENT', 20000.01, null, [$L('location_manager'), $L('role', 'gm'), $L('role', 'owner')], 1440],
            ['REPAIR', 0, null, [$L('location_manager')], 240], ['TRANSFER', 0, null, [$L('location_manager')], 120],
            ['PURCHASE', 0, 5000, [$L('role', 'purchase_manager')], 30], ['PURCHASE', 5000.01, 50000, [$L('role', 'purchase_manager'), $L('role', 'gm')], 120],
            ['PURCHASE', 50000.01, null, [$L('role', 'purchase_manager'), $L('role', 'gm'), $L('role', 'owner')], 240],
            ['REIMBURSEMENT', 0, 2000, [$L('role', 'purchase_manager'), $L('role', 'accounts_manager')], 1440], ['REIMBURSEMENT', 2000.01, null, [$L('role', 'purchase_manager'), $L('role', 'gm')], 1440],
            ['LEAVE', 0, null, [$L('direct_manager'), $L('role', 'hr_manager')], 1440], ['ATTENDANCE_CORRECTION', 0, null, [$L('direct_manager')], 1440],
            ['SALARY_ADVANCE', 0, null, [$L('direct_manager'), $L('role', 'hr_manager'), $L('role', 'gm')], 2880],
            ['PAYROLL', 0, null, [$L('role', 'hr_manager'), $L('role', 'accounts_manager'), $L('role', 'owner')], 2880],
        ];
        foreach ($rules as [$t, $min, $max, $lv, $sla]) {
            ApprovalRule::create(['request_type' => $t, 'min_amount' => $min, 'max_amount' => $max, 'levels' => $lv, 'sla_minutes' => $sla]);
        }

        // ---------- vendors ----------
        foreach ([['Amin Poultry, Kawran Bazar', 'Chicken', 'CASH', 0], ['Karim Rice Agency, Babubazar', 'Rice', 'CREDIT', 15], ['Modina Spices', 'Spices, ghee', 'CREDIT', 7], ['Fresh Veg Supplier, Jatrabari', 'Vegetables, onion', 'CASH', 0], ['Dhaka Gas Supply', 'Gas cylinders', 'CASH', 0], ['Bismillah Meat House', 'Beef, mutton', 'CREDIT', 7], ['Aarong Dairy Point', 'Milk, yogurt', 'CREDIT', 7]] as [$n, $i, $t, $d]) {
            Vendor::create(['name' => $n, 'items_supplied' => $i, 'terms' => $t, 'credit_days' => $d, 'phone' => '018'.rand(10000000, 99999999)]);
        }

        $this->items();
        $this->standingAndTemplates();

        // proxy grants
        auth()->setUser(self::$u['E-2001']);
        AttendanceService::createGrant(self::$u['E-2001'], self::$u['E-2004'], [self::$u['E-2007']->id, self::$u['E-2009']->id], null, null, 'Helpers often arrive with the sous chef');
        AttendanceService::createGrant(self::$u['E-2001'], self::$u['E-2002'], [self::$u['E-2016']->id], today()->subDays(5)->toDateString(), today()->addDays(10)->toDateString(), 'Rina\'s phone is broken');
        auth()->setUser(self::$u['E-1050']);
        AttendanceService::createGrant(self::$u['E-1050'], self::$u['E-1051'], [self::$u['E-1052']->id, self::$u['E-1053']->id]);

        // tables
        foreach (self::$loc as $l) {
            if ($l->type === 'BRANCH') {
                for ($i = 1; $i <= 12; $i++) {
                    DiningTable::create(['location_id' => $l->id, 'name' => 'T'.$i, 'seats' => $i <= 8 ? 4 : 6]);
                }
            }
        }
    }

    protected function items(): void
    {
        $raw = [
            ['RM-001', 'Chinigura Rice', 'চিনিগুঁড়া চাল', 'kg', 'Sack 25 kg', 25, 140, 100], ['RM-002', 'Broiler Chicken', 'ব্রয়লার মুরগি', 'kg', 'kg', 1, 210, 40],
            ['RM-003', 'Beef (bone-in)', 'গরুর মাংস', 'kg', 'kg', 1, 780, 30], ['RM-004', 'Onion', 'পেঁয়াজ', 'kg', 'Sack 40 kg', 40, 90, 80],
            ['RM-005', 'Ghee', 'ঘি', 'kg', 'Tin 4 kg', 4, 1400, 8], ['RM-006', 'Yogurt', 'টক দই', 'kg', 'kg', 1, 220, 10],
            ['RM-007', 'Biryani Spice Mix', 'বিরিয়ানি মসলা', 'kg', 'Pack 1 kg', 1, 1800, 3], ['RM-008', 'Soybean Oil', 'সয়াবিন তেল', 'L', 'Can 5 L', 5, 185, 40],
            ['RM-009', 'Salt', 'লবণ', 'kg', 'kg', 1, 45, 10], ['RM-010', 'Egg', 'ডিম', 'pc', 'Tray 30', 30, 13, 200],
            ['RM-011', 'Mutton', 'খাসির মাংস', 'kg', 'kg', 1, 1150, 20], ['RM-012', 'Potato', 'আলু', 'kg', 'Sack 50 kg', 50, 40, 50],
            ['RM-013', 'Flour (Atta)', 'আটা', 'kg', 'Bag 50 kg', 50, 60, 50], ['RM-014', 'Masoor Dal', 'মসুর ডাল', 'kg', 'Bag 25 kg', 25, 120, 30],
            ['RM-015', 'Green Chili', 'কাঁচা মরিচ', 'kg', 'kg', 1, 160, 5], ['RM-016', 'Sugar', 'চিনি', 'kg', 'Bag 50 kg', 50, 130, 20],
            ['RM-017', 'Milk', 'দুধ', 'L', 'L', 1, 95, 20], ['RM-018', 'Tomato', 'টমেটো', 'kg', 'kg', 1, 80, 15], ['RM-019', 'Cucumber', 'শসা', 'kg', 'kg', 1, 60, 10],
        ];
        foreach ($raw as [$c, $n, $b, $u, $bu, $f, $p, $r]) {
            Item::create(['code' => $c, 'name' => $n, 'name_bn' => $b, 'type' => 'RAW', 'category' => Item::RAW_CATEGORY_BY_CODE[$c] ?? 'Grocery', 'unit' => $u, 'buy_unit' => $bu, 'buy_factor' => $f, 'last_price' => $p, 'reorder_level' => $r]);
        }
        foreach ([['CS-001', 'Food Box 1000ml', 'ফুড বক্স', 'pc', 9, 1000], ['CS-002', 'Gas Cylinder 35 kg', 'গ্যাস সিলিন্ডার', 'pc', 4200, 4], ['CS-003', 'Tissue Pack', 'টিস্যু', 'pc', 45, 100], ['CS-004', 'Dish Soap', 'ডিশ সোপ', 'L', 220, 10]] as [$c, $n, $b, $u, $p, $r]) {
            Item::create(['code' => $c, 'name' => $n, 'name_bn' => $b, 'type' => 'CONSUMABLE', 'category' => 'Consumable', 'unit' => $u, 'last_price' => $p, 'reorder_level' => $r]);
        }
        foreach ([['AS-001', 'Chafing Dish', 'চেফিং ডিশ', 6500], ['AS-002', 'Dinner Plate', 'ডিনার প্লেট', 180], ['AS-003', 'Glass', 'গ্লাস', 60], ['AS-004', 'Fryer Basket', 'ফ্রায়ার বাস্কেট', 1500]] as [$c, $n, $b, $p]) {
            Item::create(['code' => $c, 'name' => $n, 'name_bn' => $b, 'type' => 'ASSET', 'category' => 'Equipment', 'unit' => 'pc', 'last_price' => $p]);
        }
        $semi = [
            ['SF-001', 'Chicken Biryani Base', 'চিকেন বিরিয়ানি', 'kg', 10, ['RM-001' => 3.5, 'RM-002' => 4.5, 'RM-005' => 0.4, 'RM-004' => 1.2, 'RM-006' => 0.5, 'RM-007' => 0.12, 'RM-008' => 0.3, 'RM-009' => 0.08]],
            ['SF-002', 'Beef Tehari Base', 'বিফ তেহারি', 'kg', 10, ['RM-001' => 4, 'RM-003' => 3.5, 'RM-004' => 1.5, 'RM-008' => 0.6, 'RM-007' => 0.1, 'RM-009' => 0.08, 'RM-012' => 1]],
            ['SF-003', 'Mutton Kacchi Base', 'কাচ্চি', 'kg', 10, ['RM-001' => 3.5, 'RM-011' => 4.5, 'RM-005' => 0.6, 'RM-006' => 1, 'RM-012' => 1, 'RM-007' => 0.15, 'RM-009' => 0.08]],
            ['SF-004', 'Chicken Rezala', 'চিকেন রেজালা', 'kg', 10, ['RM-002' => 6, 'RM-006' => 1, 'RM-004' => 1.5, 'RM-005' => 0.3, 'RM-017' => 1, 'RM-007' => 0.08, 'RM-009' => 0.05]],
            ['SF-005', 'Naan Dough', 'নান ডো', 'kg', 10, ['RM-013' => 7, 'RM-017' => 1.5, 'RM-016' => 0.2, 'RM-008' => 0.2, 'RM-009' => 0.05]],
            ['SF-006', 'Borhani', 'বোরহানি', 'L', 10, ['RM-006' => 6, 'RM-016' => 0.6, 'RM-009' => 0.05, 'RM-015' => 0.05, 'RM-017' => 1]],
            ['SF-007', 'Dal Tadka', 'ডাল তড়কা', 'kg', 10, ['RM-014' => 3, 'RM-004' => 0.8, 'RM-008' => 0.3, 'RM-009' => 0.06, 'RM-018' => 0.5, 'RM-015' => 0.05]],
            ['SF-008', 'Firni Cup', 'ফিরনি', 'pc', 50, ['RM-017' => 8, 'RM-001' => 0.8, 'RM-016' => 1.2]],
        ];
        $id = fn ($code) => Item::where('code', $code)->value('id');
        foreach ($semi as [$c, $n, $b, $u, $yield, $lines]) {
            $item = Item::create(['code' => $c, 'name' => $n, 'name_bn' => $b, 'type' => 'SEMI', 'category' => 'Central kitchen', 'unit' => $u, 'production_at' => 'CENTRAL']);
            $rec = Recipe::create(['item_id' => $item->id, 'yield_qty' => $yield]);
            foreach ($lines as $code => $q) {
                $rec->lines()->create(['item_id' => $id($code), 'qty' => $q]);
            }
            $item->update(['last_price' => \App\Services\ProductionService::recipeCost($item)]);
        }
        $menu = [
            ['MN-001', 'Chicken Biryani', 'চিকেন বিরিয়ানি', 'Rice', 320, ['SF-001' => 0.35, 'RM-010' => 1, 'RM-019' => 0.05]],
            ['MN-002', 'Beef Tehari', 'বিফ তেহারি', 'Rice', 280, ['SF-002' => 0.35]],
            ['MN-003', 'Mutton Kacchi', 'মাটন কাচ্চি', 'Rice', 450, ['SF-003' => 0.4]],
            ['MN-004', 'Chicken Rezala', 'চিকেন রেজালা', 'Curry', 260, ['SF-004' => 0.25]],
            ['MN-005', 'Butter Naan', 'বাটার নান', 'Bread', 60, ['SF-005' => 0.12, 'RM-005' => 0.01]],
            ['MN-006', 'Borhani', 'বোরহানি', 'Drinks', 80, ['SF-006' => 0.25]],
            ['MN-007', 'Dal Tadka', 'ডাল তড়কা', 'Curry', 120, ['SF-007' => 0.25]],
            ['MN-008', 'Chicken Tikka', 'চিকেন টিক্কা', 'Grill', 450, ['RM-002' => 0.3, 'RM-006' => 0.05, 'RM-007' => 0.01, 'RM-008' => 0.02]],
            ['MN-009', 'French Fries', 'ফ্রেঞ্চ ফ্রাই', 'Snacks', 150, ['RM-012' => 0.25, 'RM-008' => 0.05]],
            ['MN-010', 'Firni', 'ফিরনি', 'Dessert', 90, ['SF-008' => 1]],
        ];
        foreach ($menu as [$c, $n, $b, $cat, $price, $lines]) {
            $item = Item::create(['code' => $c, 'name' => $n, 'name_bn' => $b, 'type' => 'MENU', 'category' => $cat, 'unit' => 'plate', 'sale_price' => $price, 'production_at' => in_array($c, ['MN-008', 'MN-009']) ? 'BRANCH' : 'CENTRAL']);
            $rec = Recipe::create(['item_id' => $item->id, 'yield_qty' => 1]);
            foreach ($lines as $code => $q) {
                $rec->lines()->create(['item_id' => $id($code), 'qty' => $q]);
            }
            $item->update(['last_price' => \App\Services\ProductionService::recipeCost($item)]);
        }

        // opening stock
        $whStock = ['RM-001' => 340, 'RM-002' => 8, 'RM-003' => 55, 'RM-004' => 120, 'RM-005' => 20, 'RM-006' => 15, 'RM-007' => 6, 'RM-008' => 85, 'RM-009' => 40, 'RM-010' => 600, 'RM-011' => 30, 'RM-012' => 150, 'RM-013' => 200, 'RM-014' => 80, 'RM-015' => 4, 'RM-016' => 60, 'RM-017' => 40, 'RM-018' => 25, 'RM-019' => 20, 'CS-001' => 2400, 'CS-002' => 6, 'CS-003' => 300, 'CS-004' => 30, 'AS-001' => 30, 'AS-002' => 600, 'AS-003' => 600, 'AS-004' => 10];
        $ckStock = ['RM-001' => 10, 'RM-002' => 5, 'RM-005' => 1, 'RM-004' => 6, 'RM-006' => 2, 'RM-007' => 0.5, 'RM-008' => 4, 'RM-009' => 2, 'RM-017' => 10, 'RM-016' => 5, 'RM-013' => 20];
        $brStock = ['SF-001' => 12, 'SF-002' => 8, 'SF-003' => 5, 'SF-004' => 4, 'SF-005' => 6, 'SF-006' => 15, 'SF-007' => 5, 'SF-008' => 60, 'RM-002' => 10, 'RM-006' => 3, 'RM-007' => 0.5, 'RM-008' => 15, 'RM-010' => 120, 'RM-012' => 20, 'RM-019' => 5, 'RM-005' => 1, 'CS-001' => 200, 'CS-003' => 40];
        $value = 0;
        $put = function ($loc, $list) use ($id, &$value) {
            foreach ($list as $code => $q) {
                StockService::move(self::$loc[$loc]->id, $id($code), $q, 'OPENING', null, null, 'Opening stock');
                $value += $q * Item::find($id($code))->last_price;
            }
        };
        $put('WH', $whStock);
        $put('CK', $ckStock);
        foreach (['BR-GUL', 'BR-DHN', 'BR-UTT', 'BR-MIR', 'BR-BAN', 'BR-BAS'] as $b) {
            $put($b, $brStock);
        }
        $put('CAT', ['AS-001' => 6, 'AS-002' => 100]);
        LedgerService::post('Opening stock value', [['code' => '1200', 'debit' => round($value, 2)], ['code' => '3000', 'credit' => round($value, 2)]], null, null, now()->subMonths(2)->startOfMonth(), true);
    }

    protected function standingAndTemplates(): void
    {
        $id = fn ($code) => Item::where('code', $code)->value('id');
        // weekday: 0=Sun .. 6=Sat
        $par = [
            'BR-GUL' => ['SF-001' => [12, 12, 12, 14, 18, 20, 14], 'SF-002' => [8, 8, 8, 10, 12, 15, 10], 'SF-004' => [4, 4, 4, 5, 6, 8, 5], 'SF-005' => [5, 5, 5, 6, 8, 10, 6], 'SF-006' => [12, 12, 12, 15, 20, 25, 15]],
            'BR-BAN' => ['SF-001' => [15, 15, 15, 18, 22, 25, 18], 'SF-002' => [10, 10, 10, 12, 14, 18, 12], 'SF-006' => [15, 15, 15, 18, 22, 28, 18]],
            'BR-DHN' => ['SF-001' => [10, 10, 10, 12, 15, 18, 12], 'SF-003' => [5, 5, 5, 6, 8, 10, 6]],
        ];
        foreach ($par as $loc => $items) {
            foreach ($items as $code => $days) {
                foreach ($days as $wd => $q) {
                    StandingOrder::create(['location_id' => self::$loc[$loc]->id, 'item_id' => $id($code), 'weekday' => $wd, 'qty' => $q]);
                }
            }
        }
        $tpl = [
            ['BR-GUL', 'Opening checklist (kitchen)', 'খোলার চেকলিস্ট (রান্নাঘর)', 'E-2004', null, '08:30', true, 'E-2003'],
            ['BR-GUL', 'Clean & oil the fryer', 'ফ্রায়ার পরিষ্কার', null, 'E-2007', '09:00', true, 'E-2004'],
            ['BR-GUL', 'Mop dining floor', 'ডাইনিং ফ্লোর মোছা', null, 'E-2016', '09:30', true, 'E-2002'],
            ['BR-GUL', 'Closing cash & kitchen check', 'বন্ধের চেকলিস্ট', null, 'E-2002', '22:30', false, 'E-2001'],
            ['CK', 'CK hygiene round', 'সিকে পরিচ্ছন্নতা', null, 'E-1051', '07:00', true, 'E-1050'],
        ];
        foreach ($tpl as [$loc, $t, $tb, $lead, $assignee, $due, $proof, $by]) {
            TaskTemplate::create(['location_id' => self::$loc[$loc]->id, 'title' => $t, 'title_bn' => $tb, 'team_lead_id' => $lead ? self::$u[$lead]->id : null, 'assignee_id' => $assignee ? self::$u[$assignee]->id : null, 'created_by' => self::$u[$by]->id, 'recurrence' => 'DAILY', 'due_time' => $due, 'proof_required' => $proof]);
        }
    }

    /** Teams that mirror the reporting lines: [key, name, বাংলা, location, department, lead, parent key, colour, members[]]. */
    protected function teams(): void
    {
        $dep = fn (string $n) => Department::where('name', $n)->value('id');
        $defs = [
            ['MGMT', 'Head Office Management', 'হেড অফিস ম্যানেজমেন্ট', 'HO', 'Management', 'E-1002', null, 'slate', ['E-1004']],
            ['ACC', 'Accounts', 'হিসাব বিভাগ', 'HO', 'Accounts', 'E-1010', 'MGMT', 'emerald', ['E-1011']],
            ['HR', 'HR', 'এইচআর', 'HO', 'HR', 'E-1020', 'MGMT', 'pink', ['E-1021']],
            ['PUR', 'Purchase & Buyers', 'ক্রয় ও বায়ার', 'HO', 'Purchase', 'E-1030', 'MGMT', 'amber', ['E-1031', 'E-1032', 'E-1033']],
            ['WH', 'Warehouse Team', 'গুদাম টিম', 'WH', 'Warehouse', 'E-1040', 'MGMT', 'sky', ['E-1041', 'E-1042', 'E-1043']],
            ['CK', 'Central Kitchen', 'সেন্ট্রাল কিচেন', 'CK', 'Central Kitchen', 'E-1050', 'MGMT', 'orange', []],
            ['CKL', 'CK Cooking Line', 'সিকে রান্না লাইন', 'CK', 'Central Kitchen', 'E-1051', 'CK', 'orange', ['E-1052', 'E-1053', 'E-1054', 'E-1055', 'E-1056']],
            ['DSP', 'Dispatch Vans', 'ডেলিভারি ভ্যান', 'CK', 'Dispatch', 'E-1050', 'CK', 'violet', ['E-1060', 'E-1061']],
            ['CAT', 'Catering', 'ক্যাটারিং', 'CAT', 'Catering', 'E-1070', 'MGMT', 'teal', []],
            ['CATS', 'Catering Service Crew', 'ক্যাটারিং সার্ভিস টিম', 'CAT', 'Catering', 'E-1071', 'CAT', 'teal', ['E-1072', 'E-1073', 'E-1074', 'E-1075']],
            ['GUL', 'Gulshan Branch', 'গুলশান শাখা', 'BR-GUL', 'Management', 'E-2001', 'MGMT', 'indigo', ['E-2003', 'E-2018']],
            ['GULK', 'Gulshan Kitchen', 'গুলশান কিচেন', 'BR-GUL', 'Kitchen', 'E-2004', 'GUL', 'red', ['E-2005', 'E-2006', 'E-2007', 'E-2008', 'E-2009', 'E-2010']],
            ['GULF', 'Gulshan Floor (FOH)', 'গুলশান ফ্লোর', 'BR-GUL', 'FOH', 'E-2002', 'GUL', 'blue', ['E-2011', 'E-2012', 'E-2013', 'E-2014', 'E-2015', 'E-2016', 'E-2017']],
        ];
        foreach (['BR-DHN' => ['3', 'Dhanmondi', 'ধানমন্ডি'], 'BR-UTT' => ['4', 'Uttara', 'উত্তরা'], 'BR-MIR' => ['5', 'Mirpur', 'মিরপুর'], 'BR-BAN' => ['6', 'Banani', 'বনানী'], 'BR-BAS' => ['7', 'Bashundhara', 'বসুন্ধরা']] as $loc => [$d, $n, $bn]) {
            $defs[] = [$loc, "$n Branch", "$bn শাখা", $loc, 'Management', "E-{$d}001", 'MGMT', 'indigo', []];
            // head chef + floor supervisor lead their own teams below
            $defs[] = [$loc.'K', "$n Kitchen", "$bn কিচেন", $loc, 'Kitchen', "E-{$d}002", $loc, 'red', ["E-{$d}005", "E-{$d}006"]];
            $defs[] = [$loc.'F', "$n Floor (FOH)", "$bn ফ্লোর", $loc, 'FOH', "E-{$d}003", $loc, 'blue', ["E-{$d}004", "E-{$d}007", "E-{$d}008"]];
        }
        $teams = [];
        foreach ($defs as [$key, $name, $bn, $loc, $department, $lead, $parent, $color, $members]) {
            $teams[$key] = \App\Models\Team::create([
                'name' => $name, 'name_bn' => $bn, 'location_id' => self::$loc[$loc]->id, 'department_id' => $dep($department),
                'lead_id' => self::$u[$lead]->id, 'parent_id' => $parent ? $teams[$parent]->id : null, 'color' => $color,
            ]);
            // the lead belongs to the team they lead unless already placed in a parent team
            if (! self::$u[$lead]->team_id) {
                self::$u[$lead]->update(['team_id' => $teams[$key]->id]);
            }
            foreach ($members as $m) {
                self::$u[$m]->update(['team_id' => $teams[$key]->id]);
            }
        }
    }
}
