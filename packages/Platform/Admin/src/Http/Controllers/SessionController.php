<?php

declare(strict_types=1);

namespace Platform\Admin\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * TASK-ARCH-011. Login/logout for the 'platform' guard, deliberately its
 * own small controller rather than borrowing Bagisto's admin session
 * controller (which is guard-'admin'/tenant-scoped, and per this task's
 * UI-architecture decision Platform Admin does not couple itself to
 * anything in packages/Webkul - see docs/architecture/platform-admin.md,
 * "UI architecture decision").
 */
class SessionController
{
    public function create(): View|RedirectResponse
    {
        if (Auth::guard('platform')->check()) {
            return redirect()->route('platform.dashboard');
        }

        return view('platform::auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::guard('platform')->attempt($credentials)) {
            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => 'These credentials do not match our records.']);
        }

        $request->session()->regenerate();

        return redirect()->route('platform.dashboard');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('platform')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('platform.login');
    }
}
