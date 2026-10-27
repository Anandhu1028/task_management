<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\McpOAuthClient;
use App\Models\User;
use App\Services\Mcp\OAuthService;
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

        // Reusing the same auth code fails (single-use enforcement)
        $reuseResponse = $this->postJson('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $this->client->client_id,
            'redirect_uri' => $this->redirectUri,
            'code' => $authCode,
            'code_verifier' => $codeVerifier,
        ]);
        $reuseResponse->assertStatus(400);

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
}
