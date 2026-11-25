<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** QR table ordering setup. Safe to run again (php artisan db:seed --class=QrSeeder). */
class QrSeeder extends Seeder
{
    public function run(): void
    {
        $self = config('groupops.self_permissions');
        foreach (['floor_manager', 'branch_manager', 'cashier'] as $key) {
            [$name, $tier, $scope, $perms] = config('groupops.roles')[$key];
            $role = Role::firstOrCreate(['key' => $key], ['name' => $name, 'tier' => $tier, 'scope' => $scope]);
            $list = array_unique(array_merge(array_diff((array) $perms, ['self']), in_array('self', (array) $perms) ? $self : []));
            foreach ($list as $p) {
                DB::table('role_permissions')->updateOrInsert(['role_id' => $role->id, 'permission' => $p]);
            }
        }
        foreach (['owner', 'gm'] as $key) {
            if ($role = Role::where('key', $key)->first()) {
                foreach (['pos.table_calls', 'pos.qr_codes'] as $p) {
                    DB::table('role_permissions')->updateOrInsert(['role_id' => $role->id, 'permission' => $p]);
                }
            }
        }
        // the floor supervisors of every branch become Floor Managers (they get table calls and QR orders)
        $floor = Role::where('key', 'floor_manager')->value('id');
        User::where('position', 'Floor Supervisor')->update(['role_id' => $floor, 'position' => 'Floor Manager']);
    }
}
