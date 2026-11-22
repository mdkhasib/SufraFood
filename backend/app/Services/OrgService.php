<?php

namespace App\Services;

use App\Exceptions\BizException;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Org chart (who reports to whom) and teams. */
class OrgService
{
    /**
     * Reporting tree as nested arrays. Group-wide viewers see the whole company (optionally one location);
     * everyone else sees themselves and the people below them.
     */
    public static function tree(User $viewer, ?int $locationId = null, ?int $rootId = null): array
    {
        $users = User::with('role:id,name', 'location:id,code,name', 'team:id,name,color')
            ->where('status', 'ACTIVE')->where('is_super_admin', false)
            ->get(['id', 'employee_code', 'name', 'name_bn', 'position', 'phone', 'manager_id', 'team_id', 'role_id', 'location_id']);
        $byId = $users->keyBy('id');
        $children = $users->groupBy(fn ($u) => (int) $u->manager_id);
        $assignable = $viewer->hasPerm('tasks.assign') ? array_flip($viewer->assignableIds()) : [];
        $manage = $viewer->hasPerm('teams.manage');
        $manageable = $manage ? array_flip($viewer->isGroupWide() ? $users->pluck('id')->all() : array_merge($viewer->subordinateIds(), $viewer->scopedUserIds())) : [];

        $build = function (User $u, int $depth) use (&$build, $children, $assignable, $manageable, $locationId) {
            $kids = ($children[$u->id] ?? collect())
                ->filter(fn ($c) => ! $locationId || $c->location_id === $locationId || static::hasLocationBelow($c, $locationId))
                ->sortBy([['position', 'asc'], ['name', 'asc']]);
            $nodes = $depth < 12 ? $kids->map(fn ($c) => $build($c, $depth + 1))->values()->all() : [];
            $count = count($nodes) + array_sum(array_column($nodes, 'below'));

            return [
                'id' => $u->id, 'code' => $u->employee_code, 'name' => $u->name, 'name_bn' => $u->name_bn,
                'position' => $u->position, 'role' => $u->role?->name, 'phone' => $u->phone,
                'location' => $u->location?->code, 'location_name' => $u->location?->name,
                'team' => $u->team ? ['id' => $u->team->id, 'name' => $u->team->name, 'color' => $u->team->color] : null,
                'manager_id' => $u->manager_id,
                'can_assign' => isset($assignable[$u->id]), 'can_edit' => isset($manageable[$u->id]),
                'below' => $count, 'children' => $nodes,
            ];
        };

        if ($rootId && $byId->has($rootId) && ($viewer->isGroupWide() || $rootId === $viewer->id || in_array($rootId, $viewer->subordinateIds()))) {
            return [$build($byId->get($rootId), 0)];
        }
        if ($viewer->isGroupWide()) {
            $roots = $users->filter(fn ($u) => ! $u->manager_id || ! $byId->has($u->manager_id));
            if ($locationId) {
                // start from the people at this location whose manager works elsewhere (e.g. the branch manager)
                $roots = $users->filter(fn ($u) => $u->location_id === $locationId && (! $u->manager_id || $byId->get($u->manager_id)?->location_id !== $locationId));
            }

            return $roots->sortBy('id')->map(fn ($u) => $build($u, 0))->values()->all();
        }
        $me = $byId->get($viewer->id);

        return $me ? [$build($me, 0)] : [];
    }

    protected static function hasLocationBelow(User $u, int $locationId): bool
    {
        return User::whereIn('id', $u->subordinateIds())->where('location_id', $locationId)->exists();
    }

    /** Teams visible to the viewer, with lead, parent and members. */
    public static function teams(User $viewer, ?int $locationId = null)
    {
        $q = Team::with(['lead:id,name,employee_code,position', 'parent:id,name', 'location:id,code,name', 'department:id,name',
            'members' => fn ($m) => $m->where('status', 'ACTIVE')->select('id', 'name', 'employee_code', 'position', 'team_id', 'manager_id')])
            ->where('active', true);
        if (! $viewer->isGroupWide()) {
            $ids = array_merge([$viewer->id], $viewer->subordinateIds(), $viewer->scopedUserIds());
            $q->where(fn ($w) => $w->whereIn('lead_id', $ids)->orWhere('location_id', $viewer->location_id));
        }
        if ($locationId) {
            $q->where('location_id', $locationId);
        }

        return $q->orderBy('location_id')->orderByRaw('parent_id is not null')->orderBy('name')->get();
    }

    /** Teams the viewer may give a task to (team lead is the viewer or below them). */
    public static function assignableTeams(User $viewer)
    {
        if (! $viewer->featureOn('teams.org') || ! $viewer->hasPerm('tasks.assign')) {
            return collect();
        }
        $q = Team::with('lead:id,name', 'location:id,code')->withCount(['members' => fn ($m) => $m->where('status', 'ACTIVE')])->where('active', true)->whereNotNull('lead_id');
        if (! $viewer->isGroupWide()) {
            $q->whereIn('lead_id', array_merge([$viewer->id], $viewer->assignableIds()));
        }

        return $q->orderBy('location_id')->orderBy('name')->get();
    }

    /** People in the assign-task picker, direct reports first. */
    public static function assignPicker(User $u)
    {
        if (! $u->hasPerm('tasks.assign')) {
            return collect();
        }

        return User::with('location', 'team')->withCount('reports')->whereIn('id', $u->assignListIds())->where('status', 'ACTIVE')
            ->orderBy('location_id')->orderBy('name')->get()->sortByDesc(fn ($p) => $p->manager_id === $u->id)->values();
    }

    public static function setManager(User $by, User $user, ?int $managerId): User
    {
        static::ensureCanManage($by);
        if (! $by->isGroupWide() && ! $by->canManageUser($user)) {
            throw new BizException('You cannot change reporting lines for '.$user->name.'.');
        }
        if ($managerId) {
            if ($managerId === $user->id) {
                throw new BizException('A person cannot report to themselves.');
            }
            if (in_array($managerId, $user->subordinateIds())) {
                throw new BizException('That would make a loop: the new manager already reports to '.$user->name.'.');
            }
            $mgr = User::findOrFail($managerId);
            if (! $by->isGroupWide() && $mgr->id !== $by->id && ! $by->canManageUser($mgr)) {
                throw new BizException('You can only choose a manager inside your own team.');
            }
        }
        $before = ['manager_id' => $user->manager_id];
        $user->update(['manager_id' => $managerId ?: null]);
        Audit::log('org.reports_to', $user, $before, ['manager_id' => $user->manager_id]);
        if ($managerId) {
            Notifier::send($user, 'You now report to '.User::find($managerId)->name, null, 'info');
        }

        return $user;
    }

    public static function saveTeam(User $by, array $data, ?Team $team = null): Team
    {
        static::ensureCanManage($by);
        $locationId = (int) ($data['location_id'] ?? 0) ?: null;
        if (! $by->isGroupWide() && $locationId !== $by->location_id) {
            throw new BizException('You can only build teams at your own location.');
        }
        if ($team && ! $by->isGroupWide() && $team->location_id !== $by->location_id) {
            throw new BizException('This team is not at your location.');
        }
        $leadId = (int) ($data['lead_id'] ?? 0) ?: null;
        $members = array_values(array_unique(array_map('intval', $data['members'] ?? [])));
        $scope = $by->isGroupWide() ? null : array_merge([$by->id], $by->subordinateIds(), $by->scopedUserIds());
        foreach (array_filter(array_merge([$leadId], $members)) as $uid) {
            if ($scope !== null && ! in_array($uid, $scope)) {
                throw new BizException('Everyone in the team must be inside your own team or location.');
            }
        }
        $parentId = (int) ($data['parent_id'] ?? 0) ?: null;
        if ($team && $parentId) {
            $p = Team::find($parentId);
            for ($i = 0; $p && $i < 20; $i++, $p = $p->parent) {
                if ($p->id === $team->id) {
                    throw new BizException('A team cannot sit under itself.');
                }
            }
        }

        return DB::transaction(function () use ($by, $data, $team, $locationId, $leadId, $members, $parentId) {
            $fields = [
                'name' => trim($data['name'] ?? '') ?: throw new BizException('Give the team a name.'),
                'name_bn' => $data['name_bn'] ?? null, 'location_id' => $locationId, 'department_id' => ($data['department_id'] ?? null) ?: null,
                'lead_id' => $leadId, 'parent_id' => $parentId, 'color' => $data['color'] ?? 'orange', 'description' => $data['description'] ?? null,
            ];
            $before = $team?->only(array_keys($fields));
            $team ? $team->update($fields) : $team = Team::create($fields);

            // membership: removed people leave the team, added people join it
            User::where('team_id', $team->id)->whereNotIn('id', array_merge($members, array_filter([$leadId])))->update(['team_id' => null]);
            if ($members) {
                User::whereIn('id', $members)->update(['team_id' => $team->id]);
            }
            if ($leadId && ! User::find($leadId)->team_id) {
                User::where('id', $leadId)->update(['team_id' => $team->id]);
            }
            if ($leadId && filter_var($data['report_to_lead'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                User::whereIn('id', array_diff($members, [$leadId]))->update(['manager_id' => $leadId]);
            }
            Audit::log($before ? 'team.updated' : 'team.created', $team, $before, $fields + ['members' => $members]);

            return $team->fresh();
        });
    }

    public static function deleteTeam(User $by, Team $team): void
    {
        static::ensureCanManage($by);
        if (! $by->isGroupWide() && $team->location_id !== $by->location_id) {
            throw new BizException('This team is not at your location.');
        }
        User::where('team_id', $team->id)->update(['team_id' => null]);
        Team::where('parent_id', $team->id)->update(['parent_id' => $team->parent_id]);
        Audit::log('team.deleted', $team, $team->toArray());
        $team->delete();
    }

    protected static function ensureCanManage(User $by): void
    {
        if (! $by->hasPerm('teams.manage')) {
            throw new BizException('You are not allowed to change teams or reporting lines.');
        }
    }
}
