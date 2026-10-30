<?php

use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\McpAuthenticate;
use App\Http\Middleware\PwaNoStore;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(fn (Request $request): string => $request->is('pwa/*')
            ? route('pwa.login')
            : route('login'));
        $middleware->redirectUsersTo(fn (Request $request): string => $request->is('pwa/*')
            ? route('pwa.tasks.index')
            : route('dashboard'));
        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'mcp.auth' => McpAuthenticate::class,
            'pwa.nostore' => PwaNoStore::class,
        ]);
        $middleware->validateCsrfTokens(except: [
            'mcp',
            'oauth/token',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->is('mcp') || $request->is('oauth/token') || $request->expectsJson(),
        );
    })->create();
