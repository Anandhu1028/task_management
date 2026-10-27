<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReportsFeatureTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Company $company;

    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $this->company = Company::query()->where('status', 'active')->firstOrFail();
        $this->department = Department::query()
            ->where('company_id', $this->company->id)
            ->where('status', 'active')
            ->firstOrFail();
    }

    public function test_reports_page_shows_status_overdue_completed_and_team_summaries(): void
    {
        $employee = $this->createEmployee();
        $overdueTask = $this->createTask($employee, [
            'title' => 'Overdue report task',
            'description' => 'This report task missed its delivery date.',
            'status' => 'in_progress',
            'due_date' => today()->subDay()->toDateString(),
            'original_deadline' => today()->subDay()->toDateString(),
        ]);
        $completedTask = $this->createTask($employee, [
            'title' => 'Completed report task',
            'status' => 'completed',
            'completed_at' => now(),
        ]);
        $reportPeriodStart = today()->subDays(29)->startOfDay();
        $expectedTasks = Task::query()
            ->visibleTo($this->admin)
            ->whereBetween('tasks.created_at', [$reportPeriodStart, today()->endOfDay()]);
        $expectedTotal = (clone $expectedTasks)->count();
        $expectedOverdue = (clone $expectedTasks)->overdue()->count();
        $expectedCompleted = (clone $expectedTasks)->where('tasks.status', 'completed')->count();

        $response = $this->actingAs($this->admin)->get(route('reports.index'));

        $response->assertOk()
            ->assertViewIs('reports.index')
            ->assertSee('Reports')
            ->assertSee('Task status')
            ->assertSee('Task status distribution')
            ->assertSee('Percentage')
            ->assertSee('tf-reports__chart-bar', false)
            ->assertSee('tf-reports__status-summary', false)
            ->assertSee('Overdue tasks')
            ->assertSee('Completed tasks')
            ->assertSee('Department summary')
            ->assertSee('Employee summary')
            ->assertSee('data-report-tab="status"', false)
            ->assertSee('data-report-tab="overdue"', false)
            ->assertSee('data-report-tab="completed"', false)
            ->assertSee('data-report-tab="departments"', false)
            ->assertSee('data-report-tab="employees"', false)
            ->assertSee('aria-selected="true" aria-controls="report-panel-status"', false)
            ->assertSee('data-report-panel="status"', false)
            ->assertSee('data-report-panel="overdue" hidden', false)
            ->assertSee('data-report-panel="completed" hidden', false)
            ->assertSee('data-report-panel="departments" hidden', false)
            ->assertSee('data-report-panel="employees" hidden', false)
            ->assertSee('<th>Status</th><th>Total tasks</th><th>Percentage</th>', false)
            ->assertSee('report-panel-overdue', false)
            ->assertSee('report-panel-completed', false)
            ->assertSee('report-panel-departments', false)
            ->assertSee('report-panel-employees', false)
            ->assertSee('<th>Description</th>', false)
            ->assertSee('<th>Deadline</th>', false)
            ->assertSee($overdueTask->title)
            ->assertSee('This report task missed its delivery date.')
            ->assertSee($completedTask->title)
            ->assertSee($employee->name)
            ->assertDontSee('tf-reports__filter-card');

        $response->assertViewHas('totalTasks', $expectedTotal);
        $response->assertViewHas('overdueTasksCount', $expectedOverdue);
        $response->assertViewHas('completedTasksCount', $expectedCompleted);
    }

    public function test_reports_date_filter_applies_to_all_report_data(): void
    {
        $employee = $this->createEmployee();
        $currentTask = $this->createTask($employee, [
            'title' => 'Current period task',
            'status' => 'in_progress',
            'due_date' => today()->subDay()->toDateString(),
            'original_deadline' => today()->subDay()->toDateString(),
        ]);
        $currentTaskDate = today()->addDay();
        $currentTask->forceFill([
            'created_at' => $currentTaskDate->copy()->setTime(10, 0),
            'updated_at' => $currentTaskDate->copy()->setTime(10, 0),
        ])->save();
        $olderTask = $this->createTask($employee, [
            'title' => 'Outside period task',
            'status' => 'completed',
            'completed_at' => now(),
        ]);
        $olderTask->forceFill([
            'created_at' => now()->subDays(45),
            'updated_at' => now()->subDays(45),
        ])->save();

        $response = $this->actingAs($this->admin)->get(route('reports.index', [
            'from_date' => $currentTaskDate->toDateString(),
            'to_date' => $currentTaskDate->toDateString(),
        ]));

        $response->assertOk()
            ->assertSee($currentTask->title)
            ->assertDontSee($olderTask->title);
        $response->assertViewHas('totalTasks', 1);
    }

    public function test_employee_reports_are_limited_to_their_visible_tasks(): void
    {
        $employee = $this->createEmployee();
        $otherEmployee = $this->createEmployee();
        $visibleTask = $this->createTask($employee, [
            'title' => 'My report task',
            'status' => 'in_progress',
            'due_date' => today()->subDay()->toDateString(),
            'original_deadline' => today()->subDay()->toDateString(),
        ]);
        $hiddenTask = $this->createTask($otherEmployee, [
            'title' => 'Other employee report task',
            'status' => 'in_progress',
            'due_date' => today()->subDay()->toDateString(),
            'original_deadline' => today()->subDay()->toDateString(),
        ]);

        $response = $this->actingAs($employee)->get(route('reports.index'));
        $expectedVisibleCount = Task::query()
            ->visibleTo($employee)
            ->whereBetween('tasks.created_at', [today()->subDays(29)->startOfDay(), today()->endOfDay()])
            ->count();

        $response->assertOk()
            ->assertSee($visibleTask->title)
            ->assertDontSee($hiddenTask->title);
        $response->assertViewHas('totalTasks', $expectedVisibleCount);
    }

    public function test_manager_reports_are_limited_to_their_company(): void
    {
        $managerRole = Role::query()->where('name', 'Manager')->where('guard_name', 'web')->firstOrFail();
        $manager = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'role_id' => $managerRole->id,
            'status' => 'active',
        ]);
        $manager->assignRole($managerRole);

        $otherCompany = Company::create([
            'name' => 'Other Reports Company',
            'description' => null,
            'status' => 'active',
        ]);
        $otherDepartment = Department::create([
            'company_id' => $otherCompany->id,
            'name' => 'Other Reports Department',
            'designation' => 'Team Member',
            'status' => 'active',
        ]);
        $visibleTask = $this->createTask($this->createEmployee(), [
            'title' => 'Company report task',
            'status' => 'in_progress',
            'due_date' => today()->subDay()->toDateString(),
            'original_deadline' => today()->subDay()->toDateString(),
        ]);
        $otherEmployee = User::factory()->create([
            'company_id' => $otherCompany->id,
            'department_id' => $otherDepartment->id,
            'role_id' => Role::query()->where('name', 'Employee')->value('id'),
            'status' => 'active',
        ]);
        $hiddenTask = $this->createTask($otherEmployee, [
            'title' => 'Other company report task',
            'company_id' => $otherCompany->id,
            'department_id' => $otherDepartment->id,
            'status' => 'in_progress',
            'due_date' => today()->subDay()->toDateString(),
            'original_deadline' => today()->subDay()->toDateString(),
        ]);

        $response = $this->actingAs($manager)->get(route('reports.index'));

        $response->assertOk()
            ->assertSee($visibleTask->title)
            ->assertDontSee($hiddenTask->title);
        $response->assertViewHas('totalTasks', 1);
    }

    public function test_reports_require_authentication_and_task_view_permission(): void
    {
        $this->get(route('reports.index'))->assertRedirect(route('login'));

        $userWithoutTaskAccess = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'status' => 'active',
        ]);

        $this->actingAs($userWithoutTaskAccess)
            ->get(route('reports.index'))
            ->assertForbidden();
    }

    private function createEmployee(): User
    {
        $role = Role::query()->where('name', 'Employee')->where('guard_name', 'web')->firstOrFail();
        $employee = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'role_id' => $role->id,
            'status' => 'active',
        ]);
        $employee->assignRole($role);

        return $employee;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createTask(User $employee, array $attributes = []): Task
    {
        $task = Task::create(array_merge([
            'task_code' => Task::generateCode(),
            'title' => 'Reports test task',
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'task_type' => 'process',
            'priority' => 'medium',
            'status' => 'assigned',
            'assigned_to' => $employee->id,
            'original_deadline' => today()->addDays(5)->toDateString(),
            'due_date' => today()->addDays(5)->toDateString(),
            'estimated_hours' => 2,
            'expected_output' => 'Report task deliverable',
            'created_by' => $this->admin->id,
        ], $attributes));

        TaskAssignment::create([
            'task_id' => $task->id,
            'user_id' => $employee->id,
            'assigned_by' => $this->admin->id,
            'reason' => 'Initial assignment',
            'assigned_at' => now(),
            'is_active' => true,
        ]);

        return $task;
    }
}
