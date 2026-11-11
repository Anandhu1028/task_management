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

    public function test_local_login_page_offers_configured_demo_credential_fillers(): void
    {
        $environment = $this->app->environment();
        $this->app['env'] = 'local';
        config([
            'app.debug' => true,
            'demo-login.enabled' => true,
            'demo-login.accounts' => [
                ['label' => 'Admin', 'email' => 'admin@example.test', 'password' => 'admin-demo-secret', 'icon' => 'bi-shield-lock-fill'],
                ['label' => 'CEO', 'email' => 'ceo@example.test', 'password' => 'ceo-demo-secret', 'icon' => 'bi-crown-fill'],
                ['label' => 'Team Leader', 'email' => 'leader@example.test', 'password' => 'leader-demo-secret', 'icon' => 'bi-people-fill'],
            ],
        ]);

        try {
            $response = $this->get(route('login'));
        } finally {
            $this->app['env'] = $environment;
        }

        $response
            ->assertOk()
            ->assertSee('data-demo-login', false)
            ->assertSee('data-email="admin@example.test"', false)
            ->assertSee('data-password="admin-demo-secret"', false)
            ->assertSee('data-email="ceo@example.test"', false)
            ->assertSee('data-password="ceo-demo-secret"', false)
            ->assertSee('data-email="leader@example.test"', false)
            ->assertSee('data-password="leader-demo-secret"', false)
            ->assertSee('bi-shield-lock-fill', false)
            ->assertSee('bi-crown-fill', false)
            ->assertSee('bi-people-fill', false)
            ->assertSee('document.getElementById(\'email\').value = this.dataset.email;', false)
            ->assertSee('document.getElementById(\'password\').value = this.dataset.password;', false)
            ->assertSee('action="'.route('login').'"', false)
            ->assertSee('type="button"', false)
            ->assertDontSee('/login/demo', false);

        $this->assertGuest();
    }

    public function test_production_login_page_does_not_expose_quick_login_credentials(): void
    {
        $environment = $this->app->environment();
        $this->app['env'] = 'production';
        config([
            'app.debug' => true,
            'demo-login.enabled' => true,
            'demo-login.accounts' => [
                ['label' => 'Admin', 'email' => 'admin@example.test', 'password' => 'admin-demo-secret'],
                ['label' => 'CEO', 'email' => 'ceo@example.test', 'password' => 'ceo-demo-secret'],
                ['label' => 'Team Leader', 'email' => 'leader@example.test', 'password' => 'leader-demo-secret'],
            ],
        ]);

        try {
            $response = $this->get(route('login'));
        } finally {
            $this->app['env'] = $environment;
        }

        $response
            ->assertOk()
            ->assertDontSee('admin@example.test', false)
            ->assertDontSee('ceo@example.test', false)
            ->assertDontSee('leader@example.test', false)
            ->assertDontSee('admin-demo-secret', false)
            ->assertDontSee('ceo-demo-secret', false)
            ->assertDontSee('leader-demo-secret', false)
            ->assertDontSee('data-demo-login', false);
    }

    public function test_local_login_page_hides_demo_accounts_when_disabled_or_debug_is_off(): void
    {
        $environment = $this->app->environment();
        $this->app['env'] = 'local';
        config([
            'app.debug' => true,
            'demo-login.enabled' => false,
            'demo-login.accounts' => [
                ['label' => 'Admin', 'email' => 'admin@example.test', 'password' => 'admin-demo-secret'],
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
            ->assertSee('aria-label="Demo account quick fill"', false)
            ->assertDontSee('admin@example.test', false)
            ->assertDontSee('data-password=', false)
            ->assertSee('Configure demo login credentials to enable these buttons.');

        $debugDisabledResponse
            ->assertOk()
            ->assertDontSee('aria-label="Demo account quick fill"', false)
            ->assertDontSee('admin@example.test', false)
            ->assertDontSee('data-password=', false);
    }

    public function test_demo_credential_button_submits_through_the_existing_login_form(): void
    {
        $environment = $this->app->environment();
        $this->app['env'] = 'local';
        config([
            'app.debug' => true,
            'demo-login.enabled' => true,
            'demo-login.accounts' => [
                ['label' => 'Admin', 'email' => 'demo.admin@example.test', 'password' => 'local-admin-password'],
            ],
        ]);
        $user = User::factory()->create([
            'email' => 'demo.admin@example.test',
            'password' => 'local-admin-password',
            'status' => 'active',
        ]);

        try {
            $this->get(route('login'))
                ->assertOk()
                ->assertSee('data-email="demo.admin@example.test"', false)
                ->assertSee('data-password="local-admin-password"', false);
        } finally {
            $this->app['env'] = $environment;
        }

        $this->assertGuest();

        $this->post(route('login'), [
            'email' => 'demo.admin@example.test',
            'password' => 'local-admin-password',
        ])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_local_login_page_hides_demo_accounts_with_missing_credentials(): void
    {
        $environment = $this->app->environment();
        $this->app['env'] = 'local';
        config([
            'app.debug' => true,
            'demo-login.enabled' => true,
            'demo-login.accounts' => [
                ['label' => 'Admin', 'email' => 'admin@example.test', 'password' => 'admin-demo-secret'],
                ['label' => 'CEO', 'email' => 'ceo@example.test', 'password' => ''],
                ['label' => 'Team Leader', 'email' => '', 'password' => 'leader-demo-secret'],
            ],
        ]);

        try {
            $response = $this->get(route('login'));
        } finally {
            $this->app['env'] = $environment;
        }

        $response
            ->assertOk()
            ->assertSee('data-email="admin@example.test"', false)
            ->assertSee('data-password="admin-demo-secret"', false)
            ->assertSeeText('CEO')
            ->assertSeeText('Team Leader')
            ->assertDontSee('data-email="ceo@example.test"', false)
            ->assertDontSee('leader-demo-secret', false);
        $content = $response->getContent();
        $sectionStart = strpos($content, 'class="tf-login-quick"');
        $sectionEnd = strpos($content, '</section>', $sectionStart);
        $this->assertNotFalse($sectionStart);
        $this->assertNotFalse($sectionEnd);
        $quickLoginSection = substr($content, $sectionStart, $sectionEnd - $sectionStart);
        $this->assertSame(3, substr_count($quickLoginSection, 'data-demo-login'));
        $this->assertSame(1, substr_count($quickLoginSection, 'data-email='));
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
