<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\McpOAuthAuthCode;
use App\Models\McpOAuthClient;
use App\Models\User;
use App\Services\Mcp\OAuthService;
use Carbon\Carbon;
use Database\Seeders\McpOAuthClientSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class McpOAuthTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private McpOAuthClient $client;

    private string $redirectUri = 'https://chatgpt.com/aip/g-connector/oauth/callback';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $company = Company::firstOrCreate(['name' => 'Acme Corp'], ['status' => 'active']);
        $department = Department::firstOrCreate(['company_id' => $company->id, 'name' => 'Engineering'], ['status' => 'active']);
        $role = Role::firstOrCreate(['name' => 'Employee', 'guard_name' => 'web']);

        $this->user = User::factory()->create([
            'company_id' => $company->id,
            'department_id' => $department->id,
            'role_id' => $role->id,
            'status' => 'active',
        ]);
        $this->user->assignRole($role);

        $oauthService = app(OAuthService::class);
        $this->client = $oauthService->createClient(
            name: 'ChatGPT Test Connector',
            redirectUris: [$this->redirectUri],
            clientId: 'chatgpt_test_client_id',
            isConfidential: false,
        );
    }

    public function test_rfc8414_authorization_server_discovery(): void
    {
        $response = $this->getJson('/.well-known/oauth-authorization-server');

        $response->assertOk()
            ->assertJsonStructure([
                'issuer',
                'authorization_endpoint',
                'token_endpoint',
                'response_types_supported',
                'grant_types_supported',
                'code_challenge_methods_supported',
                'scopes_supported',
            ]);

        $this->assertSame(url('/oauth/authorize'), $response->json('authorization_endpoint'));
        $this->assertSame(url('/oauth/token'), $response->json('token_endpoint'));
        $this->assertContains('S256', $response->json('code_challenge_methods_supported'));
    }

    public function test_rfc9728_protected_resource_discovery(): void
    {
        $response = $this->getJson('/.well-known/oauth-protected-resource');

        $response->assertOk()
            ->assertJson([
                'resource' => url('/mcp'),
                'authorization_servers' => [url('/')],
            ]);
    }

    public function test_unauthenticated_user_redirected_to_login_on_authorize(): void
    {
        $response = $this->get('/oauth/authorize?client_id='.$this->client->client_id.'&redirect_uri='.urlencode($this->redirectUri).'&response_type=code&code_challenge=challenge123&code_challenge_method=S256');

        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_user_sees_consent_screen_with_valid_parameters(): void
    {
        $codeVerifier = Str::random(64);
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        $response = $this->actingAs($this->user)->get('/oauth/authorize?'.http_build_query([
            'client_id' => $this->client->client_id,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
            'state' => 'xyzState123',
            'scope' => 'tasks:read reports:read',
        ]));

        $response->assertOk()
            ->assertSee('Authorize Access')
            ->assertSee($this->client->name)
            ->assertSee('tasks:read');
    }

    public function test_invalid_client_or_unregistered_redirect_uri_rejected(): void
    {
        $response = $this->actingAs($this->user)->get('/oauth/authorize?'.http_build_query([
            'client_id' => 'nonexistent_client',
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'code_challenge' => 'test',
        ]));

        $response->assertStatus(400);

        $badUriResponse = $this->actingAs($this->user)->get('/oauth/authorize?'.http_build_query([
            'client_id' => $this->client->client_id,
            'redirect_uri' => 'https://malicious.com/callback',
            'response_type' => 'code',
            'code_challenge' => 'test',
        ]));

        $badUriResponse->assertStatus(400);
    }

    public function test_user_approval_issues_auth_code_and_pkce_token_exchange_succeeds(): void
    {
        $codeVerifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        // User submits "allow"
        $approveResponse = $this->actingAs($this->user)->post('/oauth/authorize', [
            'client_id' => $this->client->client_id,
            'redirect_uri' => $this->redirectUri,
            'state' => 'my_state_val',
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
            'scope' => 'tasks:read reports:read offline_access',
            'action' => 'allow',
        ]);

        $approveResponse->assertRedirect();
        $targetUrl = $approveResponse->headers->get('Location');

        $this->assertStringStartsWith($this->redirectUri, $targetUrl);
        parse_str(parse_url($targetUrl, PHP_URL_QUERY), $queryParams);

        $this->assertArrayHasKey('code', $queryParams);
        $this->assertSame('my_state_val', $queryParams['state']);
        $authCode = $queryParams['code'];

        // Token exchange with wrong PKCE verifier fails
        $wrongVerifierResponse = $this->postJson('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $this->client->client_id,
            'redirect_uri' => $this->redirectUri,
            'code' => $authCode,
            'code_verifier' => 'wrong_verifier_string_value',
        ]);
        $wrongVerifierResponse->assertStatus(400)
            ->assertJson(['error' => 'invalid_grant']);

        // Token exchange with correct PKCE verifier succeeds
        $tokenResponse = $this->postJson('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $this->client->client_id,
            'redirect_uri' => $this->redirectUri,
            'code' => $authCode,
            'code_verifier' => $codeVerifier,
        ]);

        $tokenResponse->assertOk()
            ->assertJsonStructure([
                'access_token',
                'token_type',
                'expires_in',
                'refresh_token',
                'scope',
            ]);

        $accessToken = $tokenResponse->json('access_token');
        $refreshToken = $tokenResponse->json('refresh_token');
        $this->assertNotEmpty($accessToken);
        $this->assertNotEmpty($refreshToken);

        // Refresh token exchange succeeds
        $refreshResponse = $this->postJson('/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $this->client->client_id,
            'refresh_token' => $refreshToken,
        ]);

        $refreshResponse->assertOk()
            ->assertJsonStructure([
                'access_token',
                'token_type',
                'expires_in',
                'refresh_token',
            ]);

        // Reusing the same auth code fails (single-use enforcement & replay protection)
        $reuseResponse = $this->postJson('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $this->client->client_id,
            'redirect_uri' => $this->redirectUri,
            'code' => $authCode,
            'code_verifier' => $codeVerifier,
        ]);
        $reuseResponse->assertStatus(400);
    }

    public function test_user_denial_redirects_with_access_denied_error(): void
    {
        $response = $this->actingAs($this->user)->post('/oauth/authorize', [
            'client_id' => $this->client->client_id,
            'redirect_uri' => $this->redirectUri,
            'state' => 'deny_state',
            'code_challenge' => 'dummyChallenge',
            'code_challenge_method' => 'S256',
            'action' => 'deny',
        ]);

        $response->assertRedirect();
        $targetUrl = $response->headers->get('Location');
        parse_str(parse_url($targetUrl, PHP_URL_QUERY), $queryParams);

        $this->assertSame('access_denied', $queryParams['error']);
        $this->assertSame('deny_state', $queryParams['state']);
    }

    public function test_missing_or_unsupported_pkce_challenge_rejected(): void
    {
        // Missing code challenge
        $response = $this->actingAs($this->user)->get('/oauth/authorize?'.http_build_query([
            'client_id' => $this->client->client_id,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
        ]));
        $response->assertRedirect();
        $targetUrl = $response->headers->get('Location');
        parse_str(parse_url($targetUrl, PHP_URL_QUERY), $queryParams);
        $this->assertSame('invalid_request', $queryParams['error']);

        // Unsupported method (e.g. invalid method)
        $badMethodResponse = $this->actingAs($this->user)->get('/oauth/authorize?'.http_build_query([
            'client_id' => $this->client->client_id,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'code_challenge' => 'dummyChallenge12345',
            'code_challenge_method' => 'plain_bad_method',
        ]));
        $badMethodResponse->assertRedirect();
        $targetBadUrl = $badMethodResponse->headers->get('Location');
        parse_str(parse_url($targetBadUrl, PHP_URL_QUERY), $badParams);
        $this->assertSame('invalid_request', $badParams['error']);
    }

    public function test_rfc7009_token_revocation_endpoint(): void
    {
        $oauthService = app(OAuthService::class);
        $tokenData = $oauthService->issueToken($this->client->client_id, $this->user, ['tasks:read', 'offline_access']);
        $accessToken = $tokenData['access_token'];
        $refreshToken = $tokenData['refresh_token'];

        // Token works before revocation
        $beforeResult = $oauthService->authenticateBearerToken($accessToken);
        $this->assertNotNull($beforeResult);

        // Revoke access token
        $revokeResponse = $this->postJson('/oauth/revoke', [
            'token' => $accessToken,
            'token_type_hint' => 'access_token',
            'client_id' => $this->client->client_id,
        ]);
        $revokeResponse->assertOk()
            ->assertJson(['status' => 'revoked']);

        // Authenticating with revoked token now returns null
        $afterResult = $oauthService->authenticateBearerToken($accessToken);
        $this->assertNull($afterResult);

        // Revoke refresh token
        $revokeRefresh = $this->postJson('/oauth/revoke', [
            'token' => $refreshToken,
            'token_type_hint' => 'refresh_token',
        ]);
        $revokeRefresh->assertOk();

        // Refreshing with revoked refresh token fails
        $this->expectException(\InvalidArgumentException::class);
        $oauthService->refreshToken($this->client->client_id, $refreshToken);
    }

    public function test_refresh_token_replay_attack_detection_and_lineage_revocation(): void
    {
        $oauthService = app(OAuthService::class);
        $token1 = $oauthService->issueToken($this->client->client_id, $this->user, ['tasks:read', 'offline_access']);
        $rt1 = $token1['refresh_token'];

        // Rotate token 1 -> issues token 2
        $token2 = $oauthService->refreshToken($this->client->client_id, $rt1);
        $rt2 = $token2['refresh_token'];
        $this->assertNotSame($rt1, $rt2);

        // Attempt to replay old token 1 -> Replay detection triggers
        try {
            $oauthService->refreshToken($this->client->client_id, $rt1);
            $this->fail('Expected refresh token replay exception.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('replay detected', $e->getMessage());
        }

        // Active tokens in the lineage must now be revoked
        $this->assertNull($oauthService->authenticateBearerToken($token2['access_token']));
    }

    public function test_token_audience_and_issuer_validation(): void
    {
        $oauthService = app(OAuthService::class);

        // Tampered token with invalid issuer
        $badIssuerPayload = [
            'iss' => 'https://malicious-issuer.com',
            'aud' => 'mcp-server',
            'sub' => (string) $this->user->id,
            'jti' => Str::random(40),
            'scopes' => ['tasks:read'],
            'iat' => now()->timestamp,
            'exp' => now()->addHour()->timestamp,
        ];
        $badIssuerJwt = $oauthService->encodeJwt($badIssuerPayload);
        $this->assertNull($oauthService->authenticateBearerToken($badIssuerJwt));

        // Tampered token with invalid audience
        $badAudPayload = [
            'iss' => url('/'),
            'aud' => 'wrong-foreign-server',
            'sub' => (string) $this->user->id,
            'jti' => Str::random(40),
            'scopes' => ['tasks:read'],
            'iat' => now()->timestamp,
            'exp' => now()->addHour()->timestamp,
        ];
        $badAudJwt = $oauthService->encodeJwt($badAudPayload);
        $this->assertNull($oauthService->authenticateBearerToken($badAudJwt));
    }

    public function test_seeder_creates_oauth_client_and_is_idempotent(): void
    {
        // Run the seeder
        $this->seed(McpOAuthClientSeeder::class);

        $client = McpOAuthClient::where('client_id', 'chatgpt_connector_live')->first();
        $this->assertNotNull($client);
        $this->assertSame('ChatGPT Connector', $client->name);
        $this->assertFalse($client->is_confidential, 'ChatGPT OAuth client must be a public client (is_confidential = false)');
        $this->assertNull($client->secret, 'Public OAuth client must have null secret');
        $this->assertFalse($client->is_revoked);

        $expectedScopes = ['tasks:read', 'tasks:write', 'reports:read', 'offline_access'];
        $this->assertEquals($expectedScopes, $client->scopes);

        $expectedUris = [
            'https://chatgpt.com/connector/oauth/callback',
            'https://chatgpt.com/connector/oauth/yHyws2Of_8Kj',
            'https://chatgpt.com/connector/oauth/yHyws2Qf_8Kj',
            'https://chatgpt.com/aip/g-connector/oauth/callback',
            'https://chat.openai.com/aip/g-connector/oauth/callback',
        ];
        $this->assertEquals($expectedUris, $client->redirect_uris);

        // Run the seeder a second time to ensure idempotency
        $this->seed(McpOAuthClientSeeder::class);
        $this->assertSame(1, McpOAuthClient::where('client_id', 'chatgpt_connector_live')->count());
    }

    public function test_redirect_uri_matching_is_exact_and_rejects_unregistered_or_wildcard_uris(): void
    {
        $this->seed(McpOAuthClientSeeder::class);
        $liveClient = McpOAuthClient::where('client_id', 'chatgpt_connector_live')->firstOrFail();

        // Exact registered redirect URIs must be accepted
        $this->assertTrue($liveClient->isValidRedirectUri('https://chatgpt.com/connector/oauth/callback'));
        $this->assertTrue($liveClient->isValidRedirectUri('https://chatgpt.com/connector/oauth/yHyws2Of_8Kj'));
        $this->assertTrue($liveClient->isValidRedirectUri('https://chatgpt.com/connector/oauth/yHyws2Qf_8Kj'));
        $this->assertTrue($liveClient->isValidRedirectUri('https://chatgpt.com/aip/g-connector/oauth/callback'));
        $this->assertTrue($liveClient->isValidRedirectUri('https://chat.openai.com/aip/g-connector/oauth/callback'));

        $authorizeResponse = $this->actingAs($this->user)->get('/oauth/authorize?'.http_build_query([
            'client_id' => $liveClient->client_id,
            'redirect_uri' => 'https://chatgpt.com/connector/oauth/yHyws2Qf_8Kj',
            'response_type' => 'code',
            'code_challenge' => '_8IT0Pl1REPNu2CF6UTw9_pCNECK3n5tUFfjMdZQR2U',
            'code_challenge_method' => 'S256',
            'scope' => 'offline_access tasks:read reports:read',
            'resource' => 'https://tracker-manage.focuzacademy.in/mcp',
        ]));
        $authorizeResponse->assertOk()
            ->assertSee('Authorize Access')
            ->assertSee($liveClient->name);

        // Trailing slash normalized match
        $this->assertTrue($liveClient->isValidRedirectUri('https://chatgpt.com/connector/oauth/callback/'));

        // Unregistered path on chatgpt.com must be rejected (no wildcard matching)
        $this->assertFalse($liveClient->isValidRedirectUri('https://chatgpt.com/connector/oauth/unregistered_token_123'));
        $this->assertFalse($liveClient->isValidRedirectUri('https://chatgpt.com/some/arbitrary/path'));

        // Lookalike attacker domain must be rejected
        $this->assertFalse($liveClient->isValidRedirectUri('https://chatgpt.com.attacker.com/connector/oauth/callback'));
        $this->assertFalse($liveClient->isValidRedirectUri('https://fake-chatgpt.com/callback'));

        // Insecure HTTP must be rejected
        $this->assertFalse($liveClient->isValidRedirectUri('http://chatgpt.com/connector/oauth/callback'));

        // Fragment is strictly disallowed
        $this->assertFalse($liveClient->isValidRedirectUri('https://chatgpt.com/connector/oauth/callback#token=123'));
    }

    public function test_expired_authorization_codes_are_rejected(): void
    {
        $codeVerifier = Str::random(64);
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        $oauthService = app(OAuthService::class);
        $plainCode = $oauthService->createAuthCode(
            $this->client,
            $this->user,
            $this->redirectUri,
            $codeChallenge,
            'S256',
            ['tasks:read'],
        );

        // Manually expire the authorization code
        $codeHash = hash('sha256', $plainCode);
        McpOAuthAuthCode::where('code_hash', $codeHash)->update([
            'expires_at' => Carbon::now()->subMinute(),
        ]);

        $response = $this->postJson('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $this->client->client_id,
            'redirect_uri' => $this->redirectUri,
            'code' => $plainCode,
            'code_verifier' => $codeVerifier,
        ]);

        $response->assertStatus(400)
            ->assertJson([
                'error' => 'invalid_grant',
                'error_description' => 'Authorization code has expired.',
            ]);
    }

    public function test_unsupported_scopes_are_rejected(): void
    {
        // Unsupported scope in GET /oauth/authorize
        $response = $this->actingAs($this->user)->get('/oauth/authorize?'.http_build_query([
            'client_id' => $this->client->client_id,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'code_challenge' => 'challenge12345678901234567890123456789012345',
            'code_challenge_method' => 'S256',
            'scope' => 'tasks:read admin:all_super_permission',
        ]));

        $response->assertRedirect();
        $targetUrl = $response->headers->get('Location');
        parse_str(parse_url($targetUrl, PHP_URL_QUERY), $queryParams);
        $this->assertSame('invalid_scope', $queryParams['error']);

        // Unsupported scope in POST /oauth/authorize approve
        $approveResponse = $this->actingAs($this->user)->post('/oauth/authorize', [
            'client_id' => $this->client->client_id,
            'redirect_uri' => $this->redirectUri,
            'code_challenge' => 'challenge12345678901234567890123456789012345',
            'code_challenge_method' => 'S256',
            'scope' => 'unsupported_fake_scope',
            'action' => 'allow',
        ]);

        $approveResponse->assertRedirect();
        $approveTarget = $approveResponse->headers->get('Location');
        parse_str(parse_url($approveTarget, PHP_URL_QUERY), $approveParams);
        $this->assertSame('invalid_scope', $approveParams['error']);
    }

    public function test_revoked_clients_cannot_authenticate_or_authorize(): void
    {
        $oauthService = app(OAuthService::class);

        // Issue token while client is active
        $tokenData = $oauthService->issueToken($this->client->client_id, $this->user, ['tasks:read']);
        $accessToken = $tokenData['access_token'];

        // Authenticates successfully before client revocation
        $this->assertNotNull($oauthService->authenticateBearerToken($accessToken));

        // Revoke the OAuth client
        $this->client->update(['is_revoked' => true]);

        // Client lookup returns null for revoked clients
        $this->assertNull($oauthService->findClient($this->client->client_id));

        // Existing tokens for the revoked client must be rejected
        $this->assertNull($oauthService->authenticateBearerToken($accessToken));

        // Authorization attempts for revoked client must be rejected
        $response = $this->actingAs($this->user)->get('/oauth/authorize?'.http_build_query([
            'client_id' => $this->client->client_id,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'code_challenge' => 'challenge12345678901234567890123456789012345',
            'code_challenge_method' => 'S256',
        ]));

        $response->assertStatus(400)
            ->assertJson(['error' => 'invalid_client']);
    }

    public function test_public_client_token_exchange_does_not_require_client_secret(): void
    {
        $this->assertFalse($this->client->is_confidential);
        $this->assertNull($this->client->secret);

        $codeVerifier = Str::random(64);
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        $oauthService = app(OAuthService::class);
        $plainCode = $oauthService->createAuthCode(
            $this->client,
            $this->user,
            $this->redirectUri,
            $codeChallenge,
            'S256',
            ['tasks:read'],
        );

        // Token exchange without any client_secret parameter
        $response = $this->postJson('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $this->client->client_id,
            'redirect_uri' => $this->redirectUri,
            'code' => $plainCode,
            'code_verifier' => $codeVerifier,
        ]);

        $response->assertOk()
            ->assertJsonStructure(['access_token', 'token_type', 'expires_in']);
    }
}
