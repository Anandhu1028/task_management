<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    public function create(): View
    {
        $demoAccounts = [];

        if (app()->environment('local') && config('app.debug')) {
            $demoAccounts = collect(config('demo-login.accounts', []))
                ->map(fn (array $account): array => [
                    ...$account,
                    'available' => config('demo-login.enabled')
                        && filled($account['email'] ?? null)
                        && filled($account['password'] ?? null),
                ])
                ->values()
                ->all();
        }

        return view('auth.login', compact('demoAccounts'));
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
