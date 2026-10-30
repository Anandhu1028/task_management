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

        if (app()->environment('local') && config('app.debug') && config('demo-login.enabled')) {
            $demoPassword = config('demo-login.password');

            if (filled($demoPassword)) {
                $demoAccounts = collect(config('demo-login.accounts', []))
                    ->filter(fn (array $account): bool => filled($account['email'] ?? null))
                    ->map(fn (array $account): array => [
                        ...$account,
                        'password' => $demoPassword,
                    ])
                    ->values()
                    ->all();
            }
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
