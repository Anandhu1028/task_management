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
