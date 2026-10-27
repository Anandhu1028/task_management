<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DesktopApiAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_desktop_login_returns_a_token_and_user_profile(): void
    {
        $user = User::query()->where('email', 'admin@example.com')->firstOrFail();

        $response = $this->postJson('/api/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('user.email', 'admin@example.com')
            ->assertJsonPath('user.role', 'Admin');

        $this->assertNotEmpty($response->json('token'));
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'name' => 'desktop',
        ]);
        $this->assertGuest();
    }

    public function test_desktop_login_rejects_invalid_credentials(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'admin@example.com',
            'password' => 'wrong-password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_desktop_login_rejects_inactive_users(): void
    {
        $user = $this->createEmployee('inactive');

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'test-password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_authenticated_desktop_user_can_fetch_profile_and_logout(): void
    {
        $user = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $token = $user->createToken('desktop')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/auth/user')
            ->assertOk()
            ->assertJsonPath('user.email', 'admin@example.com')
            ->assertJsonPath('user.can.view_tasks', true);

        $this->withToken($token)
            ->postJson('/api/auth/logout')
            ->assertOk()
            ->assertJsonPath('message', 'Signed out successfully.');

        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => explode('|', $token, 2)[0],
        ]);
        Auth::forgetGuards();

        $this->withToken($token)
            ->getJson('/api/auth/user')
            ->assertUnauthorized();
    }

    public function test_desktop_task_routes_require_authentication(): void
    {
        $this->getJson('/api/tasks')->assertUnauthorized();
        $this->getJson('/api/desktop/meta')->assertUnauthorized();
    }

    public function test_inactive_desktop_token_is_rejected(): void
    {
        $user = $this->createEmployee();
        Sanctum::actingAs($user);
        $user->forceFill(['status' => 'inactive'])->save();

        $this->getJson('/api/auth/user')
            ->assertForbidden()
            ->assertJsonPath('message', 'Your account is inactive. Please contact an administrator.');
    }

    private function createEmployee(string $status = 'active'): User
    {
        $role = Role::query()->where('name', 'Employee')->firstOrFail();
        $user = User::factory()->create([
            'password' => 'test-password',
            'role_id' => $role->id,
            'status' => $status,
        ]);
        $user->assignRole($role);

        return $user;
    }
}
