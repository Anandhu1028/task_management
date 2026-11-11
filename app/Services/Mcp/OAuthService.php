<?php

namespace App\Services\Mcp;

use App\Models\McpOAuthAuthCode;
use App\Models\McpOAuthClient;
use App\Models\McpOAuthToken;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

class OAuthService
{
    public const SUPPORTED_SCOPES = [
        'tasks:read',
        'tasks:write',
        'reports:read',
        'offline_access',
    ];

    public const DEFAULT_SCOPES = [
        'tasks:read',
        'reports:read',
    ];

    /**
     * Find an active OAuth client by client_id.
     * Supports both pre-registered database clients and Client ID Metadata Documents (CIMD).
     */
    public function findClient(string $clientId): ?McpOAuthClient
    {
        $clientId = trim($clientId);
        if ($clientId === '') {
            return null;
        }

        // Database registered client lookup
        $dbClient = McpOAuthClient::query()
            ->where('client_id', $clientId)
            ->where('is_revoked', false)
            ->first();

        if ($dbClient) {
            return $dbClient;
        }

        // RFC draft-ietf-oauth-client-id-metadata-document: URL-based client ID
        if (str_starts_with($clientId, 'https://')) {
            return $this->resolveClientFromMetadataDocument($clientId);
        }

        return null;
    }

    /**
     * Resolve OAuth Client from a remote Client ID Metadata Document (CIMD).
     */
    private function resolveClientFromMetadataDocument(string $clientUri): ?McpOAuthClient
    {
        $cacheKey = 'oauth_cimd_'.hash('sha256', $clientUri);

        return Cache::remember($cacheKey, now()->addHours(6), function () use ($clientUri): ?McpOAuthClient {
            try {
                $response = Http::timeout(3)
                    ->acceptJson()
                    ->get($clientUri);

                if (! $response->successful()) {
                    return null;
                }

                $meta = $response->json();
                if (! is_array($meta) || ! isset($meta['client_id']) || $meta['client_id'] !== $clientUri) {
                    return null;
                }

                $client = new McpOAuthClient;
                $client->client_id = $clientUri;
                $client->name = (string) ($meta['client_name'] ?? 'ChatGPT Client');
                $client->redirect_uris = is_array($meta['redirect_uris'] ?? null) ? $meta['redirect_uris'] : [];
                $client->scopes = is_array($meta['scope'] ?? null) ? $meta['scope'] : self::DEFAULT_SCOPES;
                $client->is_confidential = false;
                $client->is_revoked = false;

                return $client;
            } catch (Throwable) {
                return null;
            }
        });
    }

    /**
     * Create or register a new OAuth client.
     *
     * @param  array<string>  $redirectUris
     * @param  array<string>|null  $scopes
     */
    public function createClient(
        string $name,
        array $redirectUris,
        ?string $clientId = null,
        ?string $secret = null,
        ?array $scopes = null,
        bool $isConfidential = false,
    ): McpOAuthClient {
        $clientId = $clientId ?: 'client_'.Str::random(24);
        $hashedSecret = $secret ? Hash::make($secret) : null;

        return McpOAuthClient::create([
            'client_id' => $clientId,
            'name' => $name,
            'secret' => $hashedSecret,
            'redirect_uris' => $redirectUris,
            'scopes' => $scopes ?: self::DEFAULT_SCOPES,
            'is_confidential' => $isConfidential,
            'is_revoked' => false,
        ]);
    }

    /**
     * Normalize scopes string or array into valid scope array.
     *
     * @param  string|array<string>|null  $requestedScopes
     * @return array<string>
     */
    public function normalizeScopes(string|array|null $requestedScopes): array
    {
        if (is_string($requestedScopes)) {
            $requestedScopes = preg_split('/\s+/', trim($requestedScopes), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        if (empty($requestedScopes)) {
            return self::DEFAULT_SCOPES;
        }

        $filtered = array_values(array_intersect($requestedScopes, self::SUPPORTED_SCOPES));

        return ! empty($filtered) ? $filtered : self::DEFAULT_SCOPES;
    }

    /**
     * Validate whether all requested scopes are recognized and supported.
     *
     * @param  string|array<string>|null  $requestedScopes
     */
    public function areScopesSupported(string|array|null $requestedScopes): bool
    {
        if (is_string($requestedScopes)) {
            $requestedScopes = preg_split('/\s+/', trim($requestedScopes), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        if (empty($requestedScopes)) {
            return true;
        }

        foreach ($requestedScopes as $scope) {
            if (! in_array($scope, self::SUPPORTED_SCOPES, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate RFC 8707 Resource indicator parameter if provided.
     */
    public function isValidResource(?string $resource): bool
    {
        if ($resource === null || trim($resource) === '') {
            return true;
        }

        $resource = rtrim(trim($resource), '/');
        $validResources = [
            rtrim(url('/'), '/'),
            rtrim(url('/mcp'), '/'),
            rtrim((string) config('app.url'), '/'),
            rtrim((string) config('app.url'), '/').'/mcp',
            'mcp-server',
        ];

        return in_array($resource, $validResources, true);
    }

    /**
     * Create an authorization code for the authenticated user and OAuth client.
     *
     * @param  array<string>  $scopes
     */
    public function createAuthCode(
        McpOAuthClient $client,
        User $user,
        string $redirectUri,
        string $codeChallenge,
        string $codeChallengeMethod,
        array $scopes,
    ): string {
        $plainCode = 'mcp_code_'.Str::random(48);
        $codeHash = hash('sha256', $plainCode);
        $ttlSeconds = (int) config('mcp.auth_code_ttl', 600);

        McpOAuthAuthCode::create([
            'code_hash' => $codeHash,
            'client_id' => $client->client_id,
            'user_id' => $user->id,
            'redirect_uri' => $redirectUri,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => $codeChallengeMethod,
            'scopes' => $scopes,
            'is_used' => false,
            'expires_at' => Carbon::now()->addSeconds($ttlSeconds),
        ]);

        return $plainCode;
    }

    /**
     * Exchange an authorization code with PKCE verification for an access token.
     *
     * @return array{access_token: string, token_type: string, expires_in: int, refresh_token: ?string, scope: string}
     */
    public function exchangeAuthCode(
        string $clientId,
        string $code,
        string $redirectUri,
        string $codeVerifier,
        ?string $clientSecret = null,
    ): array {
        $client = $this->findClient($clientId);
        if (! $client) {
            throw new \InvalidArgumentException('Invalid client_id.');
        }

        if ($client->is_confidential) {
            if (! $clientSecret || ! Hash::check($clientSecret, (string) $client->secret)) {
                throw new \InvalidArgumentException('Invalid client_secret.');
            }
        }

        $codeHash = hash('sha256', $code);
        $authCode = McpOAuthAuthCode::query()
            ->where('code_hash', $codeHash)
            ->first();

        if (! $authCode) {
            throw new \InvalidArgumentException('Authorization code is invalid.');
        }

        // Replay detection for authorization code (RFC 6749 Section 4.1.2)
        if ($authCode->is_used) {
            // Revoke any tokens that were previously issued using this code family
            McpOAuthToken::query()
                ->where('client_id', $clientId)
                ->where('user_id', $authCode->user_id)
                ->where('created_at', '>=', $authCode->created_at)
                ->update(['is_revoked' => true]);

            throw new \InvalidArgumentException('Authorization code has already been used. Tokens revoked for security.');
        }

        if ($authCode->expires_at->isPast()) {
            throw new \InvalidArgumentException('Authorization code has expired.');
        }

        if ($authCode->client_id !== $clientId) {
            throw new \InvalidArgumentException('Client mismatch for authorization code.');
        }

        if ($authCode->redirect_uri !== $redirectUri) {
            throw new \InvalidArgumentException('Redirect URI does not match.');
        }

        // Verify PKCE (RFC 7636 / OAuth 2.1 requires S256)
        if (! $this->verifyPkce($codeVerifier, $authCode->code_challenge, $authCode->code_challenge_method)) {
            throw new \InvalidArgumentException('PKCE code_verifier challenge verification failed.');
        }

        // Mark code as used immediately
        $authCode->update(['is_used' => true]);

        $user = $authCode->user;
        if (! $user || $user->status !== 'active') {
            throw new \InvalidArgumentException('User account is not active.');
        }

        return $this->issueToken($client->client_id, $user, $authCode->scopes ?? self::DEFAULT_SCOPES);
    }

    /**
     * Refresh an existing access token using a refresh token with rotation and replay protection.
     *
     * @return array{access_token: string, token_type: string, expires_in: int, refresh_token: ?string, scope: string}
     */
    public function refreshToken(
        string $clientId,
        string $refreshToken,
        ?string $clientSecret = null,
    ): array {
        $client = $this->findClient($clientId);
        if (! $client) {
            throw new \InvalidArgumentException('Invalid client_id.');
        }

        if ($client->is_confidential) {
            if (! $clientSecret || ! Hash::check($clientSecret, (string) $client->secret)) {
                throw new \InvalidArgumentException('Invalid client_secret.');
            }
        }

        $refreshHash = hash('sha256', $refreshToken);

        // Check for replay attack: was this refresh token already revoked?
        $revokedMatch = McpOAuthToken::query()
            ->where('refresh_token_hash', $refreshHash)
            ->where('client_id', $clientId)
            ->where('is_revoked', true)
            ->first();

        if ($revokedMatch) {
            // Replay detected! Invalidate all tokens for this user and client to prevent unauthorized access.
            McpOAuthToken::query()
                ->where('user_id', $revokedMatch->user_id)
                ->where('client_id', $clientId)
                ->update(['is_revoked' => true]);

            throw new \InvalidArgumentException('Refresh token replay detected. All active tokens have been revoked.');
        }

        $tokenRecord = McpOAuthToken::query()
            ->where('refresh_token_hash', $refreshHash)
            ->where('client_id', $clientId)
            ->where('is_revoked', false)
            ->first();

        if (! $tokenRecord || $tokenRecord->isRefreshExpired()) {
            throw new \InvalidArgumentException('Refresh token is invalid or expired.');
        }

        // Revoke previous token record (Refresh Token Rotation)
        $tokenRecord->update(['is_revoked' => true]);

        $user = $tokenRecord->user;
        if (! $user || $user->status !== 'active') {
            throw new \InvalidArgumentException('User account is not active.');
        }

        return $this->issueToken($client->client_id, $user, $tokenRecord->scopes ?? self::DEFAULT_SCOPES);
    }

    /**
     * Issue an access token and optional refresh token.
     *
     * @param  array<string>  $scopes
     * @return array{access_token: string, token_type: string, expires_in: int, refresh_token: ?string, scope: string}
     */
    public function issueToken(string $clientId, User $user, array $scopes): array
    {
        $tokenId = Str::random(40);
        $expiresInSeconds = (int) config('mcp.access_token_ttl', 28800); // 8 hours default
        $expiresAt = Carbon::now()->addSeconds($expiresInSeconds);

        $plainRefreshToken = null;
        $refreshHash = null;
        $refreshExpiresAt = null;

        if (in_array('offline_access', $scopes, true)) {
            $plainRefreshToken = 'mcp_rt_'.Str::random(60);
            $refreshHash = hash('sha256', $plainRefreshToken);
            $refreshExpiresAt = Carbon::now()->addSeconds((int) config('mcp.refresh_token_ttl', 2592000));
        }

        McpOAuthToken::create([
            'token_id' => $tokenId,
            'client_id' => $clientId,
            'user_id' => $user->id,
            'scopes' => $scopes,
            'refresh_token_hash' => $refreshHash,
            'is_revoked' => false,
            'expires_at' => $expiresAt,
            'refresh_expires_at' => $refreshExpiresAt,
        ]);

        $issuer = url('/');
        $audience = config('mcp.audience', 'mcp-server');

        $jwtPayload = [
            'iss' => $issuer,
            'aud' => $audience,
            'sub' => (string) $user->id,
            'jti' => $tokenId,
            'client_id' => $clientId,
            'company_id' => $user->company_id,
            'scopes' => $scopes,
            'iat' => Carbon::now()->timestamp,
            'exp' => $expiresAt->timestamp,
        ];

        $signedAccessToken = $this->encodeJwt($jwtPayload);

        return [
            'access_token' => $signedAccessToken,
            'token_type' => 'Bearer',
            'expires_in' => $expiresInSeconds,
            'refresh_token' => $plainRefreshToken,
            'scope' => implode(' ', $scopes),
        ];
    }

    /**
     * Revoke an access token or refresh token (RFC 7009).
     */
    public function revokeToken(string $token, ?string $clientId = null, ?string $tokenTypeHint = null): bool
    {
        $token = trim($token);
        if ($token === '') {
            return false;
        }

        // Try as JWT access token
        if (substr_count($token, '.') === 2) {
            $payload = $this->decodeJwt($token);
            if ($payload && isset($payload['jti'])) {
                $query = McpOAuthToken::query()->where('token_id', $payload['jti']);
                if ($clientId) {
                    $query->where('client_id', $clientId);
                }
                $query->update(['is_revoked' => true]);

                return true;
            }
        }

        // Try as refresh token
        $refreshHash = hash('sha256', $token);
        $query = McpOAuthToken::query()->where('refresh_token_hash', $refreshHash);
        if ($clientId) {
            $query->where('client_id', $clientId);
        }

        $record = $query->first();
        if ($record) {
            $record->update(['is_revoked' => true]);

            return true;
        }

        // Try as personal access token
        if (class_exists(PersonalAccessToken::class)) {
            $sanctum = PersonalAccessToken::findToken($token);
            if ($sanctum) {
                $sanctum->delete();

                return true;
            }
        }

        return false;
    }

    /**
     * Authenticate and resolve a user and scopes from a Bearer token string.
     * Supports both MCP OAuth JWT tokens and Laravel Sanctum tokens.
     *
     * @return array{user: User, scopes: array<string>, client_id: ?string}|null
     */
    public function authenticateBearerToken(string $token): ?array
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        // Try decoding as MCP signed JWT
        if (substr_count($token, '.') === 2) {
            $payload = $this->decodeJwt($token);
            if ($payload && isset($payload['jti'], $payload['sub'], $payload['exp'])) {
                // Check expiration
                if (Carbon::now()->timestamp > $payload['exp']) {
                    return null; // Expired
                }

                // Check issuer validation
                $expectedIssuers = [
                    url('/'),
                    rtrim((string) config('app.url'), '/'),
                ];
                if (isset($payload['iss']) && ! in_array(rtrim((string) $payload['iss'], '/'), array_map(fn ($u) => rtrim((string) $u, '/'), $expectedIssuers), true)) {
                    return null; // Invalid issuer
                }

                // Check audience validation
                $expectedAudiences = [
                    config('mcp.audience', 'mcp-server'),
                    'mcp-server',
                    url('/mcp'),
                ];
                if (isset($payload['aud']) && ! in_array($payload['aud'], $expectedAudiences, true)) {
                    return null; // Invalid audience
                }

                $tokenRecord = McpOAuthToken::query()
                    ->where('token_id', $payload['jti'])
                    ->where('is_revoked', false)
                    ->where('expires_at', '>', Carbon::now())
                    ->first();

                if (! $tokenRecord) {
                    return null;
                }

                // If associated OAuth client was revoked, reject authentication
                if ($tokenRecord->client_id && ! $this->findClient($tokenRecord->client_id)) {
                    return null;
                }

                $user = User::query()->where('id', $payload['sub'])->where('status', 'active')->first();
                if ($user) {
                    return [
                        'user' => $user,
                        'scopes' => $payload['scopes'] ?? self::DEFAULT_SCOPES,
                        'client_id' => $payload['client_id'] ?? $tokenRecord->client_id,
                    ];
                }
            }
        }

        // Try Sanctum token interoperability
        if (class_exists(PersonalAccessToken::class)) {
            $sanctumToken = PersonalAccessToken::findToken($token);
            if ($sanctumToken && $sanctumToken->tokenable instanceof User) {
                $user = $sanctumToken->tokenable;
                if ($user->status === 'active') {
                    return [
                        'user' => $user,
                        'scopes' => self::DEFAULT_SCOPES,
                        'client_id' => null,
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Verify PKCE code verifier against code challenge using S256 or plain.
     */
    public function verifyPkce(string $verifier, string $challenge, string $method): bool
    {
        if ($method === 'S256') {
            $hash = hash('sha256', $verifier, true);
            $computed = rtrim(strtr(base64_encode($hash), '+/', '-_'), '=');

            return hash_equals($challenge, $computed);
        }

        // Only allow plain PKCE in local/testing environment if explicitly enabled
        if ($method === 'plain' && in_array(config('app.env'), ['local', 'testing'], true)) {
            return hash_equals($challenge, $verifier);
        }

        return false;
    }

    /**
     * Encode payload into HMAC-SHA256 JWT string.
     *
     * @param  array<string, mixed>  $payload
     */
    public function encodeJwt(array $payload): string
    {
        $header = ['typ' => 'JWT', 'alg' => 'HS256'];
        $base64Header = rtrim(strtr(base64_encode(json_encode($header, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
        $base64Payload = rtrim(strtr(base64_encode(json_encode($payload, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
        $signature = hash_hmac('sha256', "{$base64Header}.{$base64Payload}", (string) config('app.key'), true);
        $base64Signature = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

        return "{$base64Header}.{$base64Payload}.{$base64Signature}";
    }

    /**
     * Decode and verify HMAC-SHA256 JWT string.
     *
     * @return array<string, mixed>|null
     */
    public function decodeJwt(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$base64Header, $base64Payload, $base64Signature] = $parts;

        $expectedSig = hash_hmac('sha256', "{$base64Header}.{$base64Payload}", (string) config('app.key'), true);
        $computedSig = rtrim(strtr(base64_encode($expectedSig), '+/', '-_'), '=');

        if (! hash_equals($computedSig, $base64Signature)) {
            return null;
        }

        $decodedPayload = base64_decode(strtr($base64Payload, '-_', '+/'));
        if (! $decodedPayload) {
            return null;
        }

        $payload = json_decode($decodedPayload, true);

        return is_array($payload) ? $payload : null;
    }
}
