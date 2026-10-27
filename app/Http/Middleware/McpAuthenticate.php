<?php

namespace App\Http\Middleware;

use App\Services\Mcp\OAuthService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class McpAuthenticate
{
    public function __construct(
        private readonly OAuthService $oauthService,
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, ?string $requiredScope = null): Response
    {
        $authorization = $request->header('Authorization');

        if (! $authorization || ! str_starts_with($authorization, 'Bearer ')) {
            return $this->unauthorizedResponse($request, 'Bearer token is missing.');
        }

        $bearerToken = trim(substr($authorization, 7));
        $authResult = $this->oauthService->authenticateBearerToken($bearerToken);

        if (! $authResult) {
            return $this->unauthorizedResponse($request, 'Invalid or expired token.');
        }

        $user = $authResult['user'];
        $scopes = $authResult['scopes'];

        if ($requiredScope && ! in_array($requiredScope, $scopes, true)) {
            return $this->forbiddenResponse($request, "Missing required scope: {$requiredScope}");
        }

        // Set authenticated user on request and auth manager
        $request->setUserResolver(fn () => $user);
        auth()->setUser($user);
        $request->attributes->set('mcp_scopes', $scopes);

        return $next($request);
    }

    private function unauthorizedResponse(Request $request, string $message): Response
    {
        if ($request->isJson() || $request->has('jsonrpc')) {
            $id = $request->input('id');

            return response()->json([
                'jsonrpc' => '2.0',
                'id' => $id,
                'error' => [
                    'code' => -32001,
                    'message' => "Unauthorized: {$message}",
                ],
            ], 401);
        }

        return response()->json([
            'error' => 'unauthorized',
            'error_description' => $message,
        ], 401, ['WWW-Authenticate' => 'Bearer error="invalid_token"']);
    }

    private function forbiddenResponse(Request $request, string $message): Response
    {
        if ($request->isJson() || $request->has('jsonrpc')) {
            $id = $request->input('id');

            return response()->json([
                'jsonrpc' => '2.0',
                'id' => $id,
                'error' => [
                    'code' => -32003,
                    'message' => "Forbidden: {$message}",
                ],
            ], 403);
        }

        return response()->json([
            'error' => 'insufficient_scope',
            'error_description' => $message,
        ], 403);
    }
}
