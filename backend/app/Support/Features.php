<?php

namespace App\Support;

use App\Models\Feature;
use App\Models\FeatureModule;
use App\Models\Setting;

class Features
{
    protected static ?array $cache = null;
    protected static ?array $modules = null;
    protected static ?array $settings = null;

    public static function flush(): void
    {
        static::$cache = null;
        static::$modules = null;
        static::$settings = null;
    }

    /** @return array<string, array{status:string, module:string, locations:int[]}> */
    public static function all(): array
    {
        if (static::$cache === null) {
            static::$cache = [];
            foreach (Feature::with('locations:id')->get() as $f) {
                static::$cache[$f->key] = ['status' => $f->status, 'module' => $f->module, 'locations' => $f->locations->pluck('id')->all()];
            }
        }
        return static::$cache;
    }

    /** @return array<string, array{status:string, admin_access:bool}> keyed by module name */
    public static function modules(): array
    {
        if (static::$modules === null) {
            static::$modules = FeatureModule::all()->mapWithKeys(fn ($m) => [$m->name => ['status' => $m->status, 'admin_access' => (bool) $m->admin_access]])->all();
        }
        return static::$modules;
    }

    /** A module with no row yet counts as ON (so a new module never hides existing features). */
    public static function moduleOn(string $module): bool
    {
        return (static::modules()[$module]['status'] ?? 'ON') === 'ON';
    }

    public static function moduleAdminAccess(string $module): bool
    {
        return (bool) (static::modules()[$module]['admin_access'] ?? false);
    }

    /** Is a feature usable at a location? Module must be ON; groupWide users see PILOT features everywhere. */
    public static function enabled(?string $key, ?int $locationId = null, bool $groupWide = false): bool
    {
        if ($key === null) return true;
        $f = static::all()[$key] ?? null;
        if (! $f) return false;
        if (! static::moduleOn($f['module'])) return false;
        if ($f['status'] === 'ON') return true;
        if ($f['status'] === 'PILOT') return $groupWide || ($locationId && in_array($locationId, $f['locations']));
        return false;
    }

    public static function setting(string $key, $default = null)
    {
        if (static::$settings === null) {
            static::$settings = Setting::pluck('value', 'key')->all();
        }
        return static::$settings[$key] ?? (config('groupops.settings')[$key] ?? $default);
    }

    public static function setSetting(string $key, $value): void
    {
        Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        static::$settings = null;
    }

    /** Company settings: always for Super Admin; for Company Admins only when the Super Admin allows it. */
    public static function canCompanySettings(?\App\Models\User $u): bool
    {
        if (! $u) return false;
        if ($u->is_super_admin) return true;
        return $u->is_company_admin && static::enabled('company.settings', null, true) && static::moduleAdminAccess('Company settings');
    }
}
