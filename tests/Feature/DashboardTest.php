<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_renders_successfully(): void
    {
        $this->seed();
        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();

        $response = $this->actingAs($admin)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('TaskFlow');
        $response->assertSee('System Admin')
            ->assertSee('Admin')
            ->assertSee('tf-account-menu__identity', false)
            ->assertSee('tf-account-menu__signout', false)
            ->assertSee('Sign out');
        $response->assertSee('My Tasks');
        $response->assertSee('Create Task');
        $response->assertSee('Quarterly process review');
        $response->assertSee('User Management')
            ->assertSee('Company Management')
            ->assertSee('Department Management')
            ->assertSee('aria-expanded="true"', false)
            ->assertDontSee('id="usersSubmenu" hidden', false)
            ->assertDontSee('id="companiesSubmenu" hidden', false);
    }

    public function test_guest_is_redirected_to_login_from_management_pages(): void
    {
        $this->get(route('dashboard'))
            ->assertRedirect(route('login'));

        $this->get('/login')->assertOk()->assertSee('Sign in');
        $this->get('/register')->assertOk()->assertSee('Create an account');
        $this->post('/logout')->assertRedirect(route('login'));
        $this->get(route('users.index'))->assertRedirect(route('login'));
        $this->get(route('companies.index'))->assertRedirect(route('login'));
        $this->get(route('departments.index'))->assertRedirect(route('login'));
    }

    public function test_guest_cannot_create_user_company_or_department_records(): void
    {
        $this->seed();

        $this->post(route('companies.store'), [
            'name' => 'Guest Created Company',
        ])->assertRedirect(route('login'));

        $this->post(route('departments.store'), [
            'name' => 'Guest Created Department',
        ])->assertRedirect(route('login'));

        $this->post(route('users.store'), [
            'name' => 'Guest Created User',
            'email' => 'guest-created@example.test',
        ])->assertRedirect(route('login'));

        $this->assertDatabaseMissing('companies', ['name' => 'Guest Created Company']);
        $this->assertDatabaseMissing('departments', ['name' => 'Guest Created Department']);
        $this->assertDatabaseMissing('users', ['email' => 'guest-created@example.test']);
    }
}
