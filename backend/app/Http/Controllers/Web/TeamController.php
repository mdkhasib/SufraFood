<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Location;
use App\Models\Team;
use App\Models\User;
use App\Services\OrgService;
use Illuminate\Http\Request;

class TeamController extends Controller
{
    /** Org chart: who reports to whom, with quick "assign task" and "reports to" actions. */
    public function org(Request $r)
    {
        $u = $r->user();
        $loc = $r->filled('location_id') ? (int) $r->location_id : null;
        $root = $r->filled('root') ? (int) $r->root : null;
        $tree = OrgService::tree($u, $u->isGroupWide() ? $loc : null, $root);
        $managers = User::where('status', 'ACTIVE')->where('is_super_admin', false)
            ->when(! $u->isGroupWide(), fn ($q) => $q->whereIn('id', array_merge([$u->id], $u->subordinateIds(), $u->scopedUserIds())))
            ->with('location:id,code')->orderBy('name')->get(['id', 'name', 'position', 'location_id']);
        $total = 0;
        $walk = function ($nodes) use (&$walk, &$total) {
            foreach ($nodes as $n) {
                $total++;
                $walk($n['children']);
            }
        };
        $walk($tree);

        return view('org.chart', [
            'tree' => $tree, 'total' => $total, 'loc' => $loc, 'root' => $root, 'rootUser' => $root ? User::find($root) : null,
            'locations' => $u->isGroupWide() ? Location::orderBy('id')->get() : collect(),
            'managers' => $managers, 'view' => $r->input('view', 'chart'),
            'teams' => OrgService::assignableTeams($u), 'team' => OrgService::assignPicker($u),
        ]);
    }

    public function setManager(Request $r, User $user)
    {
        OrgService::setManager($r->user(), $user, $r->filled('manager_id') ? (int) $r->manager_id : null);

        return back()->with('ok', $user->name.' now reports to '.($user->fresh()->manager?->name ?? 'nobody (top level)'));
    }

    public function teams(Request $r)
    {
        $u = $r->user();
        $teams = OrgService::teams($u, $r->filled('location_id') ? (int) $r->location_id : null);
        $people = User::where('status', 'ACTIVE')->where('is_super_admin', false)
            ->when(! $u->isGroupWide(), fn ($q) => $q->whereIn('id', array_merge([$u->id], $u->subordinateIds(), $u->scopedUserIds())))
            ->with('team:id,name')->orderBy('name')->get(['id', 'name', 'employee_code', 'position', 'location_id', 'team_id']);

        return view('org.teams', [
            'teams' => $teams, 'people' => $people,
            'locations' => $u->isGroupWide() ? Location::orderBy('id')->get() : Location::where('id', $u->location_id)->get(),
            'departments' => Department::orderBy('name')->get(), 'allTeams' => Team::where('active', true)->orderBy('name')->get(['id', 'name', 'location_id']),
            'canManage' => $u->hasPerm('teams.manage'),
        ]);
    }

    public function saveTeam(Request $r)
    {
        $r->validate(['name' => 'required|max:100', 'location_id' => 'required|exists:locations,id']);
        $team = $r->filled('id') ? Team::findOrFail($r->id) : null;
        $t = OrgService::saveTeam($r->user(), $r->all(), $team);

        return back()->with('ok', 'Team “'.$t->name.'” saved');
    }

    public function deleteTeam(Request $r, Team $team)
    {
        OrgService::deleteTeam($r->user(), $team);

        return back()->with('ok', 'Team removed — its people are kept');
    }
}
