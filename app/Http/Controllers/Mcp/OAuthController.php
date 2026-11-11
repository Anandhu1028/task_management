<?php

namespace App\Http\Controllers\Mcp;

use App\Http\Controllers\Controller;
use App\Services\Mcp\McpAuditService;
use App\Services\Mcp\OAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OAuthController extends Controller
{
    public function __construct(
        private readonly OAuthService $oauthService,
        private readonly McpAuditService $auditService,
    ) {}

    /**
     * Display OAuth authorization consent prompt or validate parameters.
     * GET /oauth/authorize
     */
    public function showConsent(Request $request): View|RedirectResponse|JsonResponse
    {
        $clientId = (string) $request->query('client_id');
        $redirectUri = (string) $request->query('redirect_uri');
        $responseType = (string) $request->query('response_type');
        $state = (string) $request->query('state');
        $codeChallenge = (string) $request->query('code_challenge');
        $codeChallengeMethod = (string) $request->query('code_challenge_method', 'S256');
        $requestedScope = (string) $request->query('scope', 'tasks:read reports:read');
        $resource = (string) $request->query('resource', '');

        $client = $this->oauthService->findClient($clientId);
        if (! $client) {
            $this->auditService->log([
                'action' => 'authorize',
                'client_id' => $clientId,
                'status' => 'denied',
                'error_message' => 'Unknown or inactive client_id.',
            ]);

            return response()->json(['error' => 'invalid_client', 'error_description' => 'Unknown or inactive client_id.'], 400);
        }

        if (! $client->isValidRedirectUri($redirectUri)) {
            $this->auditService->log([
                'action' => 'authorize',
                'client_id' => $clientId,
                'status' => 'denied',
                'error_message' => 'Unauthorized redirect_uri: '.$redirectUri,
            ]);

            return response()->json(['error' => 'invalid_request', 'error_description' => 'Unauthorized redirect_uri.'], 400);
        }

        if ($resource !== '' && ! $this->oauthService->isValidResource($resource)) {
            return $this->redirectWithError($redirectUri, 'invalid_target', 'Invalid or unsupported resource indicator.', $state);
        }

        if ($responseType !== 'code') {
            return $this->redirectWithError($redirectUri, 'unsupported_response_type', 'Only code response_type is supported.', $state);
        }

        if ($request->has('scope') && ! $this->oauthService->areScopesSupported($requestedScope)) {
            return $this->redirectWithError($redirectUri, 'invalid_scope', 'The requested scope is invalid or unsupported.', $state);
        }

        if (empty($codeChallenge)) {
            return $this->redirectWithError($redirectUri, 'invalid_request', 'PKCE code_challenge is required.', $state);
        }

        if ($codeChallengeMethod !== 'S256') {
            return $this->redirectWithError($redirectUri, 'invalid_request', 'Only S256 code_challenge_method is supported.', $state);
        }

        $user = $request->user();
        if (! $user) {
            return redirect()->guest(route('login'));
        }

        $scopes = $this->oauthService->normalizeScopes($requestedScope);

        return view('mcp.oauth-authorize', [
            'client' => $client,
            'user' => $user,
            'redirectUri' => $redirectUri,
            'state' => $state,
            'codeChallenge' => $codeChallenge,
            'codeChallengeMethod' => $codeChallengeMethod,
            'scopes' => $scopes,
        ]);
    }

    /**
     * Process user authorization decision (Allow or Deny).
     * POST /oauth/authorize
     */
    public function approve(Request $request): RedirectResponse|JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return redirect()->guest(route('login'));
        }

        $validated = $request->validate([
            'client_id' => ['required', 'string'],
            'redirect_uri' => ['required', 'string'],
            'state' => ['nullable', 'string'],
            'code_challenge' => ['required', 'string'],
            'code_challenge_method' => ['required', 'string', 'in:S256,plain'],
            'scope' => ['nullable', 'string'],
            'action' => ['required', 'in:allow,deny'],
        ]);

        $client = $this->oauthService->findClient($validated['client_id']);
        if (! $client || ! $client->isValidRedirectUri($validated['redirect_uri'])) {
            return response()->json(['error' => 'invalid_client'], 400);
        }

        $redirectUri = $validated['redirect_uri'];
        $state = $validated['state'] ?? '';

        if ($validated['action'] === 'deny') {
            $this->auditService->log([
                'user' => $user,
                'action' => 'authorize',
                'client_id' => $client->client_id,
                'status' => 'denied',
                'error_message' => 'User denied authorization request.',
            ]);

            return $this->redirectWithError($redirectUri, 'access_denied', 'The user denied the authorization request.', $state);
        }

        if ($request->filled('scope') && ! $this->oauthService->areScopesSupported($validated['scope'])) {
            return $this->redirectWithError($redirectUri, 'invalid_scope', 'The requested scope is invalid or unsupported.', $state);
        }

        $scopes = $this->oauthService->normalizeScopes($validated['scope'] ?? null);

        $authCode = $this->oauthService->createAuthCode(
            $client,
            $user,
            $redirectUri,
            $validated['code_challenge'],
            $validated['code_challenge_method'],
            $scopes,
        );

        $this->auditService->log([
            'user' => $user,
            'action' => 'authorize',
            'client_id' => $client->client_id,
            'status' => 'success',
            'resource_type' => 'auth_code',
        ]);

        $separator = str_contains($redirectUri, '?') ? '&' : '?';
        $redirectUrl = $redirectUri.$separator.'code='.urlencode($authCode);
        if ($state !== '') {
            $redirectUrl .= '&state='.urlencode($state);
        }

        return redirect()->away($redirectUrl);
    }

    /**
     * Handle OAuth 2.1 token issuance.
     * POST /oauth/token
     */
    public function token(Request $request): JsonResponse
    {
        $grantType = (string) $request->input('grant_type');

        // Extract client credentials from Basic Auth header if present
        $clientId = (string) $request->input('client_id');
        $clientSecret = (string) $request->input('client_secret');

        if ($request->header('Authorization') && str_starts_with($request->header('Authorization'), 'Basic ')) {
            $decoded = base64_decode(substr((string) $request->header('Authorization'), 6));
            if ($decoded && str_contains($decoded, ':')) {
                [$basicUser, $basicPass] = explode(':', $decoded, 2);
                $clientId = $clientId ?: $basicUser;
                $clientSecret = $clientSecret ?: $basicPass;
            }
        }

        try {
            if ($grantType === 'authorization_code') {
                $code = (string) $request->input('code');
                $redirectUri = (string) $request->input('redirect_uri');
                $codeVerifier = (string) $request->input('code_verifier');

                if (empty($clientId) || empty($code) || empty($codeVerifier) || empty($redirectUri)) {
                    return response()->json([
                        'error' => 'invalid_request',
                        'error_description' => 'client_id, code, code_verifier, and redirect_uri are required.',
                    ], 400);
                }

                $token = $this->oauthService->exchangeAuthCode(
                    $clientId,
                    $code,
                    $redirectUri,
                    $codeVerifier,
                    $clientSecret ?: null,
                );

                $this->auditService->log([
                    'action' => 'token',
                    'client_id' => $clientId,
                    'status' => 'success',
                    'resource_type' => 'access_token',
                ]);

                return response()->json($token);
            }

            if ($grantType === 'refresh_token') {
                $refreshToken = (string) $request->input('refresh_token');

                if (empty($clientId) || empty($refreshToken)) {
                    return response()->json([
                        'error' => 'invalid_request',
                        'error_description' => 'client_id and refresh_token are required.',
                    ], 400);
                }

                $token = $this->oauthService->refreshToken(
                    $clientId,
                    $refreshToken,
                    $clientSecret ?: null,
                );

                $this->auditService->log([
                    'action' => 'token_refresh',
                    'client_id' => $clientId,
                    'status' => 'success',
                    'resource_type' => 'refresh_token',
                ]);

                return response()->json($token);
            }

            return response()->json([
                'error' => 'unsupported_grant_type',
                'error_description' => 'Supported grant types are authorization_code and refresh_token.',
            ], 400);
        } catch (\InvalidArgumentException $e) {
            $this->auditService->log([
                'action' => 'token',
                'client_id' => $clientId,
                'status' => 'error',
                'error_message' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'invalid_grant',
                'error_description' => $e->getMessage(),
            ], 400);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'error' => 'server_error',
                'error_description' => 'Failed to process token request.',
            ], 500);
        }
    }

    /**
     * RFC 7009: OAuth 2.0 Token Revocation.
     * POST /oauth/revoke
     */
    public function revoke(Request $request): JsonResponse
    {
        $token = (string) $request->input('token');
        $tokenTypeHint = (string) $request->input('token_type_hint', '');
        $clientId = (string) $request->input('client_id');

        if ($token !== '') {
            $this->oauthService->revokeToken($token, $clientId ?: null, $tokenTypeHint ?: null);

            $this->auditService->log([
                'action' => 'revoke',
                'client_id' => $clientId ?: null,
                'status' => 'success',
                'resource_type' => $tokenTypeHint ?: 'token',
            ]);
        }

        // RFC 7009 Section 2.2: The authorization server responds with HTTP status code 200
        return response()->json(['status' => 'revoked']);
    }

    private function redirectWithError(string $redirectUri, string $error, string $description, ?string $state = null): RedirectResponse
    {
        $separator = str_contains($redirectUri, '?') ? '&' : '?';
        $target = $redirectUri.$separator.'error='.urlencode($error).'&error_description='.urlencode($description);
        if ($state !== null && $state !== '') {
            $target .= '&state='.urlencode($state);
        }

        return redirect()->away($target);
    }
}
