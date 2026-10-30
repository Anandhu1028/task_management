<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PwaTaskTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Company $company;

    protected Department $department;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $this->company = Company::query()->where('status', 'active')->firstOrFail();
        $this->department = Department::query()->where('company_id', $this->company->id)->where('status', 'active')->firstOrFail();
    }

    public function test_guests_are_redirected_to_the_pwa_login(): void
    {
        $this->get(route('pwa.tasks.index'))->assertRedirect(route('pwa.login'));

        $task = $this->makeTask();
        $this->get(route('pwa.tasks.show', $task))->assertRedirect(route('pwa.login'));
    }

    public function test_pwa_login_redirects_to_the_pwa_task_list_not_the_dashboard(): void
    {
        $this->post(route('pwa.login.store'), ['email' => $this->admin->email, 'password' => 'password'])
            ->assertRedirect(route('pwa.tasks.index'));
        $this->assertAuthenticatedAs($this->admin);
    }

    public function test_pwa_login_ignores_an_intended_url_outside_the_pwa(): void
    {
        $this->withSession(['url.intended' => url('/users')])
            ->post(route('pwa.login.store'), ['email' => $this->admin->email, 'password' => 'password'])
            ->assertRedirect(route('pwa.tasks.index'));
    }

    public function test_main_web_login_still_redirects_to_the_dashboard(): void
    {
        $this->post(route('login'), ['email' => $this->admin->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard'));
    }

    public function test_authenticated_user_visiting_pwa_login_is_sent_to_the_task_list(): void
    {
        $this->actingAs($this->admin)->get(route('pwa.login'))->assertRedirect(route('pwa.tasks.index'));
    }

    public function test_pwa_logout_invalidates_the_session_and_returns_to_pwa_login(): void
    {
        $this->actingAs($this->admin)
            ->post(route('pwa.logout'))
            ->assertRedirect(route('pwa.login'));

        $this->assertGuest();
    }

    public function test_pwa_login_screen_renders_with_csrf_and_without_admin_chrome(): void
    {
        $this->get(route('pwa.login'))
            ->assertOk()
            ->assertSee(route('pwa.login.store'), false)
            ->assertSee('name="_token"', false)
            ->assertDontSee('tf-sidebar', false);
    }

    public function test_task_list_shows_summary_without_the_admin_sidebar(): void
    {
        $this->makeTask(['title' => 'Overdue report', 'due_date' => now()->subDays(3)->toDateString(), 'original_deadline' => now()->subDays(3)->toDateString(), 'status' => 'in_progress']);
        $this->makeTask(['title' => 'Finished thing', 'status' => 'completed']);

        $this->actingAs($this->admin)
            ->get(route('pwa.tasks.index'))
            ->assertOk()
            ->assertSee('Total tasks')
            ->assertSee('Overdue')
            ->assertSee('Awaiting assignment')
            ->assertSee('At risk')
            ->assertSee('In progress')
            ->assertSee('Completed')
            ->assertSee('Sign out')
            ->assertSee('Overdue report')
            ->assertDontSee('tf-sidebar', false)
            ->assertDontSee(route('users.index'), false)
            ->assertDontSee(route('reports.index'), false)
            ->assertHeader('Cache-Control');
    }

    public function test_task_list_search_and_filters_work(): void
    {
        $this->makeTask(['title' => 'Alpha budget review', 'status' => 'in_progress']);
        $this->makeTask(['title' => 'Beta onboarding', 'status' => 'completed']);
        $this->makeTask(['title' => 'Gamma overdue', 'status' => 'assigned', 'due_date' => now()->subDay()->toDateString()]);

        $this->actingAs($this->admin)->get(route('pwa.tasks.index', ['search' => 'Alpha']))
            ->assertSee('Alpha budget review')->assertDontSee('Beta onboarding');

        $this->actingAs($this->admin)->get(route('pwa.tasks.index', ['filter' => 'done']))
            ->assertSee('Beta onboarding')->assertDontSee('Alpha budget review');

        $this->actingAs($this->admin)->get(route('pwa.tasks.index', ['filter' => 'active']))
            ->assertSee('Alpha budget review')->assertDontSee('Beta onboarding');

        $this->actingAs($this->admin)->get(route('pwa.tasks.index', ['filter' => 'overdue']))
            ->assertSee('Gamma overdue')->assertDontSee('Alpha budget review');
    }

    public function test_task_list_is_paginated(): void
    {
        foreach (range(1, 17) as $i) {
            $this->makeTask(['title' => "Paged task {$i}"]);
        }

        $response = $this->actingAs($this->admin)->get(route('pwa.tasks.index'))->assertOk();
        $this->assertCount(15, $response->viewData('tasks')->items());
        $response->assertSee('Page 1 of 2');
    }

    public function test_invalid_filter_is_rejected(): void
    {
        $this->actingAs($this->admin)->get(route('pwa.tasks.index', ['filter' => 'nope']))->assertSessionHasErrors('filter');
    }

    public function test_task_detail_renders_split_view_with_selected_task(): void
    {
        $task = $this->makeTask(['title' => 'Detail target', 'description' => 'Full description text', 'status' => 'in_progress']);
        $other = $this->makeTask(['title' => 'Sibling task']);

        $this->actingAs($this->admin)
            ->get(route('pwa.tasks.show', $task))
            ->assertOk()
            ->assertSee('Detail target')
            ->assertSee('Full description text')
            ->assertSee('Sibling task')
            ->assertSee('aria-current="page"', false)
            ->assertSee('Workflow progress')
            ->assertDontSee('data-tab="subtasks"', false)
            ->assertDontSee('data-tab="files"', false)
            ->assertDontSee('tf-sidebar', false);

        $this->assertNotSame($task->id, $other->id);
    }

    public function test_employee_cannot_open_or_list_tasks_outside_their_scope(): void
    {
        $employee = $this->makeEmployee();
        $mine = $this->makeTask(['title' => 'Mine only', 'assigned_to' => $employee->id, 'status' => 'assigned']);
        TaskAssignment::create(['task_id' => $mine->id, 'user_id' => $employee->id, 'assigned_by' => $this->admin->id, 'assigned_at' => now(), 'is_active' => true]);
        $theirs = $this->makeTask(['title' => 'Somebody else secret']);

        $this->actingAs($employee)->get(route('pwa.tasks.index'))
            ->assertOk()->assertSee('Mine only')->assertDontSee('Somebody else secret');

        $this->actingAs($employee)->get(route('pwa.tasks.show', $mine))->assertOk();
        $this->actingAs($employee)->get(route('pwa.tasks.show', $theirs))->assertForbidden();
    }

    public function test_assignee_sees_start_work_and_pwa_action_redirects_back_to_pwa(): void
    {
        $employee = $this->makeEmployee();
        $task = $this->makeTask(['status' => 'assigned', 'assigned_to' => $employee->id]);
        TaskAssignment::create(['task_id' => $task->id, 'user_id' => $employee->id, 'assigned_by' => $this->admin->id, 'assigned_at' => now(), 'is_active' => true]);

        $this->actingAs($employee)->get(route('pwa.tasks.show', $task))
            ->assertOk()->assertSee('Start work');

        $this->actingAs($employee)
            ->post(route('tasks.start', $task), ['return_to' => 'pwa', 'note' => 'Kicking off', 'start_date' => now()->toDateString()])
            ->assertRedirect(route('pwa.tasks.show', $task));

        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'status' => 'in_progress']);
    }

    public function test_main_app_task_actions_still_redirect_to_the_main_task_page(): void
    {
        $employee = $this->makeEmployee();
        $task = $this->makeTask(['status' => 'assigned', 'assigned_to' => $employee->id]);
        TaskAssignment::create(['task_id' => $task->id, 'user_id' => $employee->id, 'assigned_by' => $this->admin->id, 'assigned_at' => now(), 'is_active' => true]);

        $this->actingAs($employee)
            ->post(route('tasks.start', $task), ['note' => 'Kicking off', 'start_date' => now()->toDateString()])
            ->assertRedirect(route('tasks.show', $task));
    }

    public function test_admin_dashboard_and_admin_modules_are_unchanged(): void
    {
        $this->actingAs($this->admin)->get(route('dashboard'))->assertOk();
        $this->actingAs($this->admin)->get(route('users.index'))->assertOk();
        $this->actingAs($this->admin)->get(route('tasks.index'))->assertOk();
    }

    public function test_manifest_is_scoped_to_the_pwa(): void
    {
        $manifest = json_decode(file_get_contents(public_path('manifest.webmanifest')), true);

        $this->assertSame('/pwa/login', $manifest['start_url']);
        $this->assertSame('/pwa/', $manifest['scope']);
        $this->assertSame('standalone', $manifest['display']);
    }

    public function test_service_worker_does_not_cache_navigations_or_api_responses(): void
    {
        $sw = file_get_contents(public_path('sw.js'));

        $this->assertStringContainsString("request.mode === 'navigate'", $sw);
        $this->assertStringContainsString('ASSETS.includes', $sw);
    }

    /** @param array<string, mixed> $attributes */
    private function makeTask(array $attributes = []): Task
    {
        return Task::create(array_merge([
            'task_code' => Task::generateCode(),
            'title' => 'PWA task '.Task::generateCode(),
            'expected_output' => 'Completed task output',
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'task_type' => 'process',
            'priority' => 'medium',
            'estimated_hours' => 2,
            'due_date' => now()->addDays(10)->toDateString(),
            'original_deadline' => now()->addDays(10)->toDateString(),
            'created_by' => $this->admin->id,
        ], $attributes));
    }

    private function makeEmployee(): User
    {
        $employee = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'status' => 'active',
        ]);
        $employee->assignRole('Employee');

        return $employee;
    }
}
