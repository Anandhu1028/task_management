<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->status !== 'active') {
            $token = $request->user()?->currentAccessToken();
            if (is_object($token) && method_exists($token, 'delete')) {
                $token->delete();
            }

            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => 'Your account is inactive. Please contact an administrator.',
                ], 403);
            }

            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()
                ->route('login')
                ->withErrors(['email' => 'Your account is inactive. Please contact an administrator.']);
        }

        return $next($request);
    }
}
