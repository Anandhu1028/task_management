<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Login/logout for the installable PWA. Kept separate from the admin web login
 * so each flow always lands back inside its own application.
 */
class PwaAuthController extends Controller
{
    public function create(): View
    {
        return view('pwa.login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();
        $request->session()->regenerate();

        // Only honour an intended URL that is inside the PWA; anything else
        // (e.g. "/" or "/tasks" from an old install) would open the admin app.
        $intended = (string) $request->session()->pull('url.intended', '');
        $path = ltrim((string) parse_url($intended, PHP_URL_PATH), '/');

        if ($intended !== '' && Str::startsWith($path, 'pwa/') && ! Str::startsWith($path, 'pwa/login')) {
            return redirect()->to($intended);
        }

        return redirect()->route('pwa.tasks.index');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('pwa.login');
    }
}
