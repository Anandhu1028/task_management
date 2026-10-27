<?php

namespace App\Services\Mcp;

use App\Models\McpOAuthAuthCode;
use App\Models\McpOAuthClient;
use App\Models\McpOAuthToken;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class OAuthService
{
    public const SUPPORTED_SCOPES = [
        'tasks:read',
        'reports:read',
        'offline_access',
    ];

    public const DEFAULT_SCOPES = [
        'tasks:read',
        'reports:read',
    ];

    /**
     * Find an active OAuth client by client_id.
     */
    public function findClient(string $clientId): ?McpOAuthClient
    {
        return McpOAuthClient::query()
            ->where('client_id', $clientId)
            ->where('is_revoked', false)
            ->first();
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

        McpOAuthAuthCode::create([
            'code_hash' => $codeHash,
            'client_id' => $client->client_id,
            'user_id' => $user->id,
            'redirect_uri' => $redirectUri,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => $codeChallengeMethod,
            'scopes' => $scopes,
            'is_used' => false,
            'expires_at' => Carbon::now()->addMinutes(10),
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
            ->where('is_used', false)
            ->where('expires_at', '>', Carbon::now())
            ->first();

        if (! $authCode) {
            throw new \InvalidArgumentException('Authorization code is invalid or has expired.');
        }

        if ($authCode->client_id !== $clientId) {
            throw new \InvalidArgumentException('Client mismatch for authorization code.');
        }

        if ($authCode->redirect_uri !== $redirectUri) {
            throw new \InvalidArgumentException('Redirect URI does not match.');
        }

        // Verify PKCE (RFC 7636)
        if (! $this->verifyPkce($codeVerifier, $authCode->code_challenge, $authCode->code_challenge_method)) {
            throw new \InvalidArgumentException('PKCE code_verifier challenge verification failed.');
        }

        // Mark code as used
        $authCode->update(['is_used' => true]);

        $user = $authCode->user;
        if (! $user || $user->status !== 'active') {
            throw new \InvalidArgumentException('User account is not active.');
        }

        return $this->issueToken($client->client_id, $user, $authCode->scopes ?? self::DEFAULT_SCOPES);
    }

    /**
     * Refresh an existing access token using a refresh token.
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
        $tokenRecord = McpOAuthToken::query()
            ->where('refresh_token_hash', $refreshHash)
            ->where('client_id', $clientId)
            ->where('is_revoked', false)
            ->first();

        if (! $tokenRecord || $tokenRecord->isRefreshExpired()) {
            throw new \InvalidArgumentException('Refresh token is invalid or expired.');
        }

        // Revoke previous token record
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
        $expiresInSeconds = 28800; // 8 hours
        $expiresAt = Carbon::now()->addSeconds($expiresInSeconds);

        $plainRefreshToken = null;
        $refreshHash = null;
        $refreshExpiresAt = null;

        if (in_array('offline_access', $scopes, true)) {
            $plainRefreshToken = 'mcp_rt_'.Str::random(60);
            $refreshHash = hash('sha256', $plainRefreshToken);
            $refreshExpiresAt = Carbon::now()->addDays(30);
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

        $jwtPayload = [
            'iss' => config('app.url'),
            'aud' => 'mcp-server',
            'sub' => (string) $user->id,
            'jti' => $tokenId,
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
     * Authenticate and resolve a user and scopes from a Bearer token string.
     * Supports both MCP OAuth JWT tokens and Laravel Sanctum tokens.
     *
     * @return array{user: User, scopes: array<string>}|null
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
                if (Carbon::now()->timestamp > $payload['exp']) {
                    return null; // Expired
                }

                $tokenRecord = McpOAuthToken::query()
                    ->where('token_id', $payload['jti'])
                    ->where('is_revoked', false)
                    ->where('expires_at', '>', Carbon::now())
                    ->first();

                if (! $tokenRecord) {
                    return null;
                }

                $user = User::query()->where('id', $payload['sub'])->where('status', 'active')->first();
                if ($user) {
                    return [
                        'user' => $user,
                        'scopes' => $payload['scopes'] ?? self::DEFAULT_SCOPES,
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

        if ($method === 'plain') {
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
