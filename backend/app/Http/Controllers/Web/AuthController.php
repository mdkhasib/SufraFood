<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function form()
    {
        return Auth::check() ? redirect('/') : view('auth.login');
    }

    public function login(Request $r)
    {
        $r->validate(['login' => 'required', 'password' => 'required']);
        $u = User::findForLogin((string) $r->login);
        if (! $u || ! Hash::check($r->password, $u->password)) {
            return back()->withInput()->withErrors(['login' => $r->input('mode') === 'phone' ? 'Wrong mobile number or password.' : 'Wrong employee ID / mobile number or password.']);
        }
        if ($u->status !== 'ACTIVE') {
            return back()->withInput()->withErrors(['login' => 'Your account is not active.']);
        }
        Auth::login($u, true);
        $r->session()->regenerate();
        $r->session()->put('lang', $u->language);
        Audit::log('auth.login.web', $u);

        return redirect()->intended('/');
    }

    public function logout(Request $r)
    {
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return redirect('/login');
    }

    public function lang(Request $r, string $lang)
    {
        if (in_array($lang, ['en', 'bn'])) {
            $r->session()->put('lang', $lang);
            $r->user()?->update(['language' => $lang]);
        }

        return back();
    }
}
