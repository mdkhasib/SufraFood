<?php

namespace App\Models;

use App\Support\Features;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $guarded = [];

    protected $hidden = ['password', 'remember_token'];

    protected ?array $permCache = null;

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
            'is_company_admin' => 'boolean',
            'joined_at' => 'date',
            'last_seen_at' => 'datetime',
            'basic_salary' => 'float',
            'house_rent' => 'float',
            'medical' => 'float',
            'conveyance' => 'float',
            'approval_limit' => 'float',
            'advance_limit' => 'float',
            'own_money_limit' => 'float',
            'discount_limit' => 'float',
        ];
    }

    public function role() { return $this->belongsTo(Role::class); }
    public function location() { return $this->belongsTo(Location::class); }
    public function department() { return $this->belongsTo(Department::class); }
    public function manager() { return $this->belongsTo(User::class, 'manager_id'); }
    public function reports() { return $this->hasMany(User::class, 'manager_id'); }
    public function team() { return $this->belongsTo(Team::class); }
    public function ledTeams() { return $this->hasMany(Team::class, 'lead_id'); }

    public function displayName(?string $lang = null): string
    {
        $lang = $lang ?? app()->getLocale();
        return ($lang === 'bn' && $this->name_bn) ? $this->name_bn : $this->name;
    }

    public function setPhoneAttribute($value): void
    {
        $this->attributes['phone'] = ($value === null || trim((string) $value) === '') ? null : static::normalizePhone((string) $value);
    }

    /** Normalise a Bangladeshi mobile number: +880 1712-345678 / 8801712345678 / 1712345678 → 01712345678. */
    public static function normalizePhone(string $raw): string
    {
        $d = preg_replace('/\D+/', '', $raw);
        if (str_starts_with($d, '880')) $d = '0'.substr($d, 3);
        elseif (strlen($d) === 10 && str_starts_with($d, '1')) $d = '0'.$d;
        return $d;
    }

    /** Find a user by employee ID (E-2008, e2008, E 2008) or mobile number (any common format). */
    public static function findForLogin(string $login): ?self
    {
        $login = trim($login);
        if ($login === '') return null;
        $code = strtoupper(preg_replace('/\s+/', '', $login));
        if (preg_match('/^([A-Z]{1,3})-?(\d{3,6})$/', $code, $m)) {
            return static::where('employee_code', $m[1].'-'.$m[2])->first() ?? static::where('employee_code', $code)->first();
        }
        $phone = static::normalizePhone($login);
        if (strlen($phone) >= 6) {
            return static::where('phone', $phone)->first();
        }
        return static::where('employee_code', $code)->first();
    }

    public function grossSalary(): float
    {
        return $this->basic_salary + $this->house_rent + $this->medical + $this->conveyance;
    }

    /** Group-wide users (scope ALL) see PILOT features at every location. */
    public function isGroupWide(): bool
    {
        return $this->is_super_admin || $this->is_company_admin || ($this->role && $this->role->scope === 'ALL');
    }

    /** Super Admin has every permission, but only for features (and modules) that are switched on. */
    public function featureOn(?string $key): bool
    {
        return Features::enabled($key, $this->location_id, $this->isGroupWide());
    }

    /** Raw role permissions (no feature filter). */
    public function rolePermissions(): array
    {
        if ($this->permCache === null) {
            $this->permCache = $this->role ? $this->role->permissionKeys() : [];
        }
        return $this->permCache;
    }

    /** Four gates: feature ON -> at location -> role permission -> (scope checked by callers). */
    public function hasPerm(string $perm): bool
    {
        $def = config('groupops.permissions')[$perm] ?? null;
        if (! $def) return false;
        if ($this->is_super_admin) return $this->featureOn($def[1]);
        if (! in_array($perm, $this->rolePermissions())) return false;
        return $this->featureOn($def[1]);
    }

    public function hasAnyPerm(array $perms): bool
    {
        foreach ($perms as $p) if ($this->hasPerm($p)) return true;
        return false;
    }

    /** All effective permissions (for the mobile app). */
    public function effectivePermissions(): array
    {
        $keys = $this->is_super_admin ? array_keys(config('groupops.permissions')) : $this->rolePermissions();
        return array_values(array_filter($keys, fn ($p) => $this->hasPerm($p)));
    }

    public function enabledFeatures(): array
    {
        return array_values(array_filter(array_keys(config('groupops.features')), fn ($k) => $this->featureOn($k)));
    }

    /** IDs of everybody below this user in the reporting tree. */
    public function subordinateIds(): array
    {
        $ids = [];
        $frontier = [$this->id];
        for ($depth = 0; $depth < 8 && $frontier; $depth++) {
            $next = User::whereIn('manager_id', $frontier)->pluck('id')->all();
            $next = array_diff($next, $ids, [$this->id]);
            $ids = array_merge($ids, $next);
            $frontier = $next;
        }
        return array_values($ids);
    }

    protected ?array $subCache = null;

    /** subordinateIds() memoised for list rendering. */
    public function subordinateIdsCached(): array
    {
        return $this->subCache ??= $this->subordinateIds();
    }

    /** People this user may see / manage according to role scope. */
    public function scopedUserIds(): array
    {
        if ($this->isGroupWide()) return User::pluck('id')->all();
        $scope = $this->role?->scope ?? 'SELF';
        $team = $this->subordinateIds();
        if ($scope === 'LOCATION') {
            return array_values(array_unique(array_merge(User::where('location_id', $this->location_id)->pluck('id')->all(), $team)));
        }
        if ($scope === 'TEAM') return $team;
        return [];
    }

    /** Everyone this user may give a task to (server-side rule). */
    public function assignableIds(): array
    {
        $ids = $this->isGroupWide()
            ? User::where('is_super_admin', false)->pluck('id')->all()
            : array_merge($this->subordinateIds(), $this->scopedUserIds());

        // never upward: nobody assigns work to their own manager chain
        return array_values(array_diff(array_unique($ids), [$this->id], $this->managerChainIds()));
    }

    /** My manager, their manager, … up to the top. */
    public function managerChainIds(): array
    {
        $ids = [];
        $m = $this->manager_id;
        for ($i = 0; $m && $i < 12 && ! in_array($m, $ids); $i++) {
            $ids[] = $m;
            $m = User::whereKey($m)->value('manager_id');
        }

        return $ids;
    }

    /**
     * People shown in the "Assign task" list. In LEADERS mode (default) the list holds the
     * direct reports plus everybody below who leads people (managers, team leads) —
     * front-line staff such as waiters are reached through their lead, a team, or the org chart.
     */
    public function assignListIds(): array
    {
        $all = $this->assignableIds();
        if (Features::setting('tasks.assign_list', 'LEADERS') === 'ALL') {
            return $all;
        }
        $direct = User::where('manager_id', $this->id)->pluck('id')->all();
        $leaders = array_unique(array_merge(
            User::whereIn('manager_id', $all)->distinct()->pluck('manager_id')->all(),
            Team::whereIn('lead_id', $all)->where('active', true)->pluck('lead_id')->all(),
        ));

        return array_values(array_intersect($all, array_unique(array_merge($direct, $leaders))));
    }

    public function canManageUser(User $other): bool
    {
        return in_array($other->id, $this->scopedUserIds()) || in_array($other->id, $this->subordinateIds());
    }

    public function tier(): string
    {
        if ($this->is_super_admin) return 'SUPER';
        return $this->role?->tier ?? 'T1';
    }
}
