<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuthManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_active_user_can_sign_in_and_sign_out(): void
    {
        $user = $this->createEmployee();

        $this->from(route('login'))
            ->post(route('login'), [
                'email' => $user->email,
                'password' => 'test-password',
            ])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);

        $this->post(route('logout'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_invalid_credentials_are_rejected(): void
    {
        $user = $this->createEmployee();

        $this->from(route('login'))
            ->post(route('login'), [
                'email' => $user->email,
                'password' => 'wrong-password',
            ])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_local_login_page_offers_role_based_credential_fillers(): void
    {
        $environment = $this->app->environment();
        $this->app['env'] = 'local';
        config([
            'app.debug' => true,
            'demo-login.enabled' => true,
            'demo-login.password' => 'local-demo-password',
            'demo-login.accounts' => [
                ['label' => 'Admin', 'email' => 'admin@example.test'],
                ['label' => 'CEO', 'email' => 'ceo@example.test'],
                ['label' => 'Team Leader', 'email' => 'leader@example.test'],
            ],
        ]);

        try {
            $response = $this->get(route('login'));
        } finally {
            $this->app['env'] = $environment;
        }

        $response
            ->assertOk()
            ->assertSee('data-email="admin@example.test" data-password="local-demo-password"', false)
            ->assertSee('data-email="ceo@example.test" data-password="local-demo-password"', false)
            ->assertSee('data-email="leader@example.test" data-password="local-demo-password"', false)
            ->assertSee('Development only');
    }

    public function test_production_login_page_does_not_expose_quick_login_credentials(): void
    {
        $environment = $this->app->environment();
        $this->app['env'] = 'production';
        config([
            'app.debug' => true,
            'demo-login.enabled' => true,
            'demo-login.password' => 'local-demo-password',
            'demo-login.accounts' => [
                ['label' => 'Admin', 'email' => 'admin@example.test'],
                ['label' => 'CEO', 'email' => 'ceo@example.test'],
                ['label' => 'Team Leader', 'email' => 'leader@example.test'],
            ],
        ]);

        try {
            $response = $this->get(route('login'));
        } finally {
            $this->app['env'] = $environment;
        }

        $response
            ->assertOk()
            ->assertDontSee('data-email="admin@example.test"', false)
            ->assertDontSee('data-email="ceo@example.test"', false)
            ->assertDontSee('data-email="leader@example.test"', false)
            ->assertDontSee('local-demo-password', false);
    }

    public function test_local_login_page_hides_demo_accounts_when_disabled_or_debug_is_off(): void
    {
        $environment = $this->app->environment();
        $this->app['env'] = 'local';
        config([
            'app.debug' => true,
            'demo-login.enabled' => false,
            'demo-login.password' => 'local-demo-password',
            'demo-login.accounts' => [
                ['label' => 'Admin', 'email' => 'admin@example.test'],
            ],
        ]);

        try {
            $disabledResponse = $this->get(route('login'));
            config([
                'app.debug' => false,
                'demo-login.enabled' => true,
            ]);
            $debugDisabledResponse = $this->get(route('login'));
        } finally {
            $this->app['env'] = $environment;
        }

        $disabledResponse
            ->assertOk()
            ->assertDontSee('aria-label="Local testing quick login"', false)
            ->assertDontSee('admin@example.test', false)
            ->assertDontSee('local-demo-password', false);

        $debugDisabledResponse
            ->assertOk()
            ->assertDontSee('aria-label="Local testing quick login"', false)
            ->assertDontSee('admin@example.test', false)
            ->assertDontSee('local-demo-password', false);
    }

    public function test_registration_creates_an_inactive_employee_and_ignores_submitted_role(): void
    {
        $adminRole = Role::query()->where('name', 'Admin')->firstOrFail();

        $this->post(route('register'), [
            'name' => 'New Employee',
            'email' => ' New.Employee@example.test ',
            'password' => 'test-password',
            'password_confirmation' => 'test-password',
            'role_id' => $adminRole->id,
            'status' => 'active',
        ])
            ->assertRedirect(route('login'))
            ->assertSessionHas('status');

        $user = User::query()->where('email', 'new.employee@example.test')->firstOrFail();
        $employeeRole = Role::query()->where('name', 'Employee')->firstOrFail();

        $this->assertSame('inactive', $user->status);
        $this->assertTrue($user->requires_approval);
        $this->assertSame($employeeRole->id, $user->role_id);
        $this->assertTrue($user->hasRole('Employee'));
        $this->assertFalse($user->hasRole('Admin'));
        $this->assertGuest();
    }

    public function test_only_an_admin_can_approve_a_public_registration(): void
    {
        $this->post(route('register'), [
            'name' => 'Pending Employee',
            'email' => 'pending.employee@example.test',
            'password' => 'test-password',
            'password_confirmation' => 'test-password',
        ])->assertRedirect(route('login'));

        $pendingUser = User::query()->where('email', 'pending.employee@example.test')->firstOrFail();
        $employee = $this->createEmployee();

        $this->actingAs($employee)
            ->post(route('users.toggle-status', $pendingUser))
            ->assertForbidden();

        $this->actingAs($employee)
            ->put(route('users.update', $pendingUser))
            ->assertForbidden();

        $this->assertDatabaseHas('users', [
            'id' => $pendingUser->id,
            'status' => 'inactive',
            'requires_approval' => true,
        ]);

        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $this->actingAs($admin)
            ->post(route('users.toggle-status', $pendingUser))
            ->assertRedirect();

        $this->assertDatabaseHas('users', [
            'id' => $pendingUser->id,
            'status' => 'active',
            'requires_approval' => false,
        ]);
    }

    public function test_inactive_user_cannot_sign_in_or_continue_an_existing_session(): void
    {
        $user = $this->createEmployee('inactive');

        $this->from(route('login'))
            ->post(route('login'), [
                'email' => $user->email,
                'password' => 'test-password',
            ])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_sidebar_and_routes_respect_role_permissions(): void
    {
        $user = $this->createEmployee();
        Role::query()->where('name', 'Employee')->firstOrFail()->revokePermissionTo('tasks.view');

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('User Management')
            ->assertDontSee('href="'.route('tasks.index').'"', false);

        $this->get(route('tasks.index'))->assertForbidden();
    }

    private function createEmployee(string $status = 'active'): User
    {
        $role = Role::query()->where('name', 'Employee')->firstOrFail();
        $user = User::factory()->create([
            'email' => fake()->unique()->safeEmail(),
            'password' => 'test-password',
            'role_id' => $role->id,
            'status' => $status,
        ]);
        $user->assignRole($role);

        return $user;
    }
}
