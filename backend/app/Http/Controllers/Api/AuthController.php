<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\User;
use App\Services\Audit;
use App\Services\DashboardService;
use App\Support\Features;
use App\Support\P;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $r)
    {
        $r->validate(['login' => 'required|string', 'password' => 'required|string']);
        $u = User::findForLogin((string) $r->login);
        if (! $u || ! Hash::check($r->password, $u->password)) {
            return response()->json(['message' => 'Wrong employee ID / mobile number or password.'], 422);
        }
        if ($u->status !== 'ACTIVE') {
            return response()->json(['message' => 'Your account is not active.'], 422);
        }
        $device = $r->input('device_id');
        if ((int) Features::setting('attendance.device_binding', 0) && $device && $u->device_id && $u->device_id !== $device) {
            return response()->json(['message' => 'This phone is not registered for you. Ask HR to reset your device.'], 422);
        }
        if ($device && ! $u->device_id) {
            $u->update(['device_id' => $device]);
        }
        $token = $u->createToken($r->input('device_name', 'mobile'))->plainTextToken;
        auth()->setUser($u);
        Audit::log('auth.login', $u, null, ['device' => $device]);

        return ['token' => $token, 'user' => P::me($u)];
    }

    public function logout(Request $r)
    {
        $r->user()->currentAccessToken()?->delete();

        return ['ok' => true];
    }

    public function me(Request $r)
    {
        return P::me($r->user());
    }

    public function language(Request $r)
    {
        $r->validate(['language' => 'required|in:en,bn']);
        $r->user()->update(['language' => $r->language]);

        return ['ok' => true];
    }

    public function home(Request $r)
    {
        $h = DashboardService::home($r->user());
        $h['tasks'] = $h['tasks']->map(fn ($t) => P::task($t))->values();

        return $h;
    }

    public function notifications(Request $r)
    {
        return AppNotification::where('user_id', $r->user()->id)->latest('id')->limit(60)->get()
            ->map(fn ($n) => ['id' => $n->id, 'type' => $n->type, 'title' => $n->title, 'body' => $n->body, 'read' => (bool) $n->read_at, 'at' => $n->created_at->format('d M, h:i A'), 'ago' => $n->created_at->diffForHumans()]);
    }

    public function readAll(Request $r)
    {
        AppNotification::where('user_id', $r->user()->id)->whereNull('read_at')->update(['read_at' => now()]);

        return ['ok' => true];
    }

    public function dashboard(Request $r)
    {
        abort_unless($r->user()->hasPerm('reports.management'), 403);

        return DashboardService::management($r->user());
    }
}
