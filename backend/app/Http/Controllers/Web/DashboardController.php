<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\ApprovalStep;
use App\Models\Requisition;
use App\Services\ApprovalService;
use App\Services\DashboardService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class DashboardController extends Controller
{
    public function index(Request $r)
    {
        $u = $r->user();
        $home = DashboardService::home($u);
        $mgmt = ($u->hasPerm('reports.management') || $u->isGroupWide()) ? DashboardService::management($u) : null;
        $inbox = ApprovalService::inbox($u)->take(6);

        return view('dashboard', compact('home', 'mgmt', 'inbox', 'u'));
    }

    public function notifications(Request $r)
    {
        $items = AppNotification::where('user_id', $r->user()->id)->latest('id')->paginate(40);

        return view('notifications', compact('items'));
    }

    public function readAll(Request $r)
    {
        AppNotification::where('user_id', $r->user()->id)->whereNull('read_at')->update(['read_at' => now()]);

        return back()->with('ok', 'All marked as read');
    }

    public function approvals(Request $r)
    {
        $inbox = ApprovalService::inbox($r->user());
        $mine = ApprovalStep::where('acted_by', $r->user()->id)->with('approvable')->latest('acted_at')->limit(20)->get();

        return view('approvals', compact('inbox', 'mine'));
    }

    public function act(Request $r, ApprovalStep $step)
    {
        ApprovalService::act($step, $r->user(), $r->input('decision') === 'approve', $r->input('comment'));

        return back()->with('ok', $r->input('decision') === 'approve' ? 'Approved' : 'Rejected');
    }

    public function profile(Request $r)
    {
        return view('profile', ['u' => $r->user()->load('role', 'location', 'manager', 'department')]);
    }

    public function password(Request $r)
    {
        $r->validate(['current' => 'required', 'password' => 'required|min:6|confirmed']);
        if (! Hash::check($r->current, $r->user()->password)) {
            return back()->with('error', 'Current password is wrong.');
        }
        $r->user()->update(['password' => Hash::make($r->password)]);

        return back()->with('ok', 'Password changed');
    }
}
