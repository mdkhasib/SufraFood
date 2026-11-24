<?php

namespace Database\Seeders;

use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Home delivery demo setup. Safe to run again (php artisan db:seed --class=DeliverySeeder). */
class DeliverySeeder extends Seeder
{
    public function run(): void
    {
        // roles + permissions from config (new roles, and delivery.manage for branch managers)
        $self = config('groupops.self_permissions');
        foreach (['online_support', 'delivery_rider', 'branch_manager'] as $key) {
            [$name, $tier, $scope, $perms] = config('groupops.roles')[$key];
            $role = Role::firstOrCreate(['key' => $key], ['name' => $name, 'tier' => $tier, 'scope' => $scope]);
            $list = array_unique(array_merge(array_diff((array) $perms, ['self']), in_array('self', (array) $perms) ? $self : []));
            foreach ($list as $p) {
                DB::table('role_permissions')->updateOrInsert(['role_id' => $role->id, 'permission' => $p]);
            }
        }
        foreach (['owner', 'gm'] as $key) { // “all permissions” roles get the new ones too
            if ($role = Role::where('key', $key)->first()) {
                foreach (['delivery.manage', 'delivery.order'] as $p) {
                    DB::table('role_permissions')->updateOrInsert(['role_id' => $role->id, 'permission' => $p]);
                }
            }
        }

        $loc = fn (string $code) => Location::where('code', $code)->value('id');
        $uid = fn (string $code) => User::where('employee_code', $code)->value('id');
        $mk = function (string $code, string $name, string $bn, string $role, string $l, string $mgr, string $pos, string $phone, float $gross, bool $rider = false) use ($loc, $uid) {
            if (User::where('employee_code', $code)->exists()) {
                return;
            }
            $basic = round($gross * 0.6);
            User::create([
                'employee_code' => $code, 'name' => $name, 'name_bn' => $bn, 'phone' => $phone, 'password' => PlatformSeeder::hash(),
                'role_id' => Role::where('key', $role)->value('id'), 'location_id' => $loc($l), 'manager_id' => $uid($mgr), 'position' => $pos,
                'joined_at' => now()->subMonths(8)->startOfMonth(), 'can_deliver' => $rider,
                'basic_salary' => $basic, 'house_rent' => round($gross * 0.3), 'medical' => round($gross * 0.06), 'conveyance' => $gross - $basic - round($gross * 0.3) - round($gross * 0.06),
                'payout_method' => 'MFS', 'payout_account' => $phone,
            ]);
        };
        // online / call-centre team at head office
        $mk('E-1080', 'Sabrina Yasmin', 'সাবরিনা ইয়াসমিন', 'online_support', 'HO', 'E-1002', 'Online Order Executive', '01790000080', 26000);
        $mk('E-1081', 'Tamim Hasan', 'তামিম হাসান', 'online_support', 'HO', 'E-1080', 'Online Order Executive', '01790000081', 22000);
        // a dedicated rider at Gulshan; waiters also deliver
        $mk('E-2019', 'Riaz Uddin', 'রিয়াজ উদ্দিন', 'delivery_rider', 'BR-GUL', 'E-2002', 'Delivery Rider', '01790000219', 14000, true);
        $mk('E-3009', 'Sajjad Hossain', 'সাজ্জাদ হোসেন', 'delivery_rider', 'BR-DHN', 'E-3003', 'Delivery Rider', '01790000309', 14000, true);
        User::whereIn('employee_code', ['E-2012', 'E-2013', 'E-2014', 'E-2015'])->update(['can_deliver' => true]);
        User::where('position', 'Waiter')->whereIn('location_id', Location::where('type', 'BRANCH')->pluck('id'))->update(['can_deliver' => true]);

        // rider bonus per delivery: Gulshan & Banani pay more (longer distances); others use the company default
        Location::where('code', 'BR-GUL')->whereNull('delivery_rate')->update(['delivery_rate' => 60]);
        Location::where('code', 'BR-BAN')->whereNull('delivery_rate')->update(['delivery_rate' => 60]);
    }
}
