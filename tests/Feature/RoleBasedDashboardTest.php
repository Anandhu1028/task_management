<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\Task;
use App\Models\User;
use App\Services\DashboardService;
use Database\Seeders\RoleBasedDashboardSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleBasedDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleBasedDashboardSeeder::class);
    }

    public function test_unauthenticated_user_redirected_to_login(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->get('/dashboard/data')->assertRedirect(route('login'));
    }

    public function test_dashboard_analytics_expose_all_supported_statuses(): void
    {
        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $dashboard = app(DashboardService::class)->getDashboardData($admin, 'Admin');
        $statusColors = array_column($dashboard['statusDistribution']['items'], 'color', 'key');
        $department = $dashboard['departmentAnalytics'][0];
        $departmentStatuses = array_values(array_filter(
            array_keys($department),
            static fn (string $key): bool => in_array($key, Task::STATUSES, true),
        ));

        $this->assertSame(Task::STATUSES, array_column($dashboard['statusDistribution']['items'], 'key'));
        $this->assertSame(Task::query()->count(), $dashboard['statusDistribution']['total']);
        $this->assertSame('#1e3a8a', $statusColors['in_progress']);
        $this->assertSame('#2563eb', $statusColors['completed']);
        $this->assertSame('#f0f5ff', $statusColors['assigned']);
        $this->assertSame('#94a3b8', $statusColors['notified']);
        $this->assertSame('#cbd5e1', $statusColors['cancelled']);
        $this->assertSame(Task::STATUSES, $departmentStatuses);
        $this->assertArrayNotHasKey('todo', $department);
        $this->assertArrayNotHasKey('in_review', $department);
    }

    public function test_admin_dashboard_renders_workflow_and_deadline_kpis_with_organization_wide_data(): void
    {
        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();

        $response = $this->actingAs($admin)->get(route('dashboard'));

        $response->assertOk();
        $response->assertViewIs('dashboard.admin');
        $response->assertSee('Admin Dashboard');
        $response->assertSee('Total Companies');
        $response->assertSee('Total Departments');
        $response->assertSee('Total Tasks');
        $response->assertSee('In Progress');
        $response->assertSee('Overdue');
        $response->assertSee('At Risk');
        $response->assertSee('Completed');
        $response->assertSee('Company Overview');
        $response->assertSee('Department-wise performance');
        $response->assertSee('Employees by company');
        $response->assertSee('id="companyComparisonChart"', false);
        $response->assertSee('id="departmentPerformanceSection"', false);
        $response->assertSee('id="employeeHeadcountChart"', false);
        $response->assertSee('data-department-performance-company', false);
        $response->assertDontSee('Company Name');
        $response->assertDontSee('Department Overview');
        $response->assertDontSee('Team Leader Performance');
        $response->assertDontSee('Employee Performance');
        $response->assertSee('Task Code & Title', false);
        $response->assertSee('Task Completion Trends');
        $response->assertSee('Organization Task Status');
        $response->assertSee('Recent Organizational Tasks');
        $response->assertDontSee('Upcoming Deadlines');
        $this->assertDashboardSectionsAreOrdered($response->getContent());
        $response->assertViewHas('departmentPerformanceAnalytics', fn (array $departments): bool => count($departments) > 0);
        $response->assertViewHas('employeeHeadcountByCompany', fn (array $companies): bool => array_sum(array_column($companies, 'employee_count')) > 0);
    }

    public function test_ceo_dashboard_renders_workflow_and_deadline_kpis_with_management_analytics(): void
    {
        $ceo = User::query()->where('email', 'ceo@example.com')->firstOrFail();

        $response = $this->actingAs($ceo)->get(route('dashboard'));

        $response->assertOk();
        $response->assertViewIs('dashboard.ceo');
        $response->assertSee('CEO Dashboard');
        $response->assertSee('Total Companies');
        $response->assertSee('Total Departments');
        $response->assertSee('Total Tasks');
        $response->assertSee('In Progress');
        $response->assertSee('Overdue');
        $response->assertSee('At Risk');
        $response->assertSee('Completed');
        $response->assertSee('Task Completion Trends');
        $response->assertSee('Organization Task Status');
        $response->assertSee('Company Overview');
        $response->assertSee('Department-wise performance');
        $response->assertSee('Employees by company');
        $response->assertSee('id="departmentPerformanceSection"', false);
        $response->assertSee('id="employeeHeadcountChart"', false);
        $response->assertDontSee('Company Name');
        $response->assertDontSee('Department Overview');
        $response->assertDontSee('Team Leader Performance');
        $response->assertDontSee('Employee Performance');
        $response->assertSee('Task Code & Title', false);
        $response->assertSee('Recent Organizational Tasks');
        $response->assertDontSee('Upcoming Deadlines');
        $this->assertDashboardSectionsAreOrdered($response->getContent());
    }

    private function assertDashboardSectionsAreOrdered(string $dashboardHtml): void
    {
        $sectionPositions = array_map(
            fn (string $section): int|false => strpos($dashboardHtml, $section),
            [
                'Total Companies',
                'Task Completion Trends',
                'Organization Task Status',
                'Company Overview',
                'Department-wise performance',
                'Employees by company',
                'Recent Organizational Tasks',
            ],
        );
        $sortedPositions = $sectionPositions;
        sort($sortedPositions);

        $this->assertSame($sortedPositions, $sectionPositions);
    }

    public function test_team_leader_dashboard_renders_department_scope_and_deadline_kpis(): void
    {
        $tl = User::query()->where('email', 'karthik@gmail.com')->firstOrFail();

        $response = $this->actingAs($tl)->get(route('dashboard'));

        $response->assertOk();
        $response->assertViewIs('dashboard.team-leader');
        $response->assertSee('Team Leader Dashboard');
        $response->assertSee('Department Total Tasks');
        $response->assertSee('In Progress');
        $response->assertSee('Overdue');
        $response->assertSee('At Risk');
        $response->assertSee('Completed');
        $response->assertDontSee('Total Companies');
        $response->assertDontSee('Total Departments');
        $response->assertSee('Team members task graph');
        $response->assertSee('Total department graph');
        $response->assertDontSee('Employee Name');
        $response->assertDontSee('Total Assigned');
        $response->assertSee('id="deptWorkloadChart"', false);
        $response->assertSee('id="departmentWeeklyChart"', false);
        $response->assertSee('data-labels=', false);
        $response->assertSee('data-periods=', false);
        $response->assertSee('data-weekly=', false);
        $response->assertSee('data-member-range', false);
        $response->assertSee('data-week-range', false);
        $response->assertViewHas('departmentWorkload', function (array $workload): bool {
            return count($workload['weekly']['labels']) === 8
                && count($workload['weekly']['created']) === 8
                && count($workload['weekly']['completed']) === 8
                && array_sum($workload['weekly']['created']) > 0
                && array_sum($workload['weekly']['completed']) > 0
                && array_sum($workload['memberPeriods']['this_week']['completed']) > 0
                && array_sum($workload['memberPeriods']['this_week']['in_progress']) > 0
                && array_sum($workload['memberPeriods']['this_week']['overdue']) > 0
                && count($workload['memberPeriods']['last_4_weeks']['completed']) === count($workload['labels'])
                && count($workload['memberPeriods']['last_4_weeks']['in_progress']) === count($workload['labels'])
                && count($workload['memberPeriods']['last_4_weeks']['overdue']) === count($workload['labels'])
                && array_sum($workload['memberPeriods']['last_4_weeks']['completed']) > 0
                && array_sum($workload['memberPeriods']['last_4_weeks']['in_progress']) > 0
                && array_sum($workload['memberPeriods']['last_4_weeks']['overdue']) > 0;
        });
        $response->assertSee('My Personal Tasks');
        $response->assertSee('Recent Department Tasks');
    }

    public function test_team_leader_cannot_access_unauthorized_department_data(): void
    {
        $tl = User::query()->where('email', 'karthik@gmail.com')->firstOrFail();
        $otherDept = Department::query()->where('name', 'Product Design')->firstOrFail();

        // Attempting to inject other department ID in query or API
        $response = $this->actingAs($tl)->get(route('dashboard.data', [
            'department_id' => $otherDept->id,
        ]));

        $response->assertOk();
        $data = $response->json('data');

        // Verify the response is strictly scoped to the TL's own department
        $this->assertEquals('Team Leader', $response->json('role'));
        $this->assertEquals($tl->department_id, $data['selectedFilters']['department_id']);
    }

    public function test_employee_dashboard_renders_personal_tasks_and_deadline_kpis(): void
    {
        $emp = User::query()->where('email', 'anandhu@gmail.com')->firstOrFail();

        $response = $this->actingAs($emp)->get(route('dashboard'));

        $response->assertOk();
        $response->assertViewIs('dashboard.employee');
        $response->assertSee('Employee Dashboard');
        $response->assertSee('My Tasks');
        $response->assertSee('In Progress');
        $response->assertSee('Overdue');
        $response->assertSee('At Risk');
        $response->assertSee('Completed');
        $response->assertDontSee('Total Companies');
        $response->assertDontSee('Total Departments');
        $response->assertDontSee('Company Overview');
        $response->assertSee('My Task Completion');
        $response->assertSee('My Workload');
        $response->assertSee('My Priority Overview');
        $response->assertSee('My Recent Tasks');
    }

    public function test_employee_my_tasks_summary_opens_only_assigned_tasks(): void
    {
        $employee = User::query()->where('email', 'anandhu@gmail.com')->firstOrFail();
        $otherEmployee = User::query()->where('email', 'rahul@example.com')->firstOrFail();
        $department = Department::query()->findOrFail($employee->department_id);
        $assignedTask = $this->createVisibilityTask($employee, $department, $employee, 'Employee assigned visibility task');
        $createdTask = $this->createVisibilityTask($employee, $department, null, 'Employee created visibility task');
        $otherTask = $this->createVisibilityTask(
            User::query()->where('email', 'admin@example.com')->firstOrFail(),
            $department,
            $otherEmployee,
            'Other employee visibility task',
        );
        $sharedTask = $this->createVisibilityTask($employee, $department, $otherEmployee, 'Employee shared visibility task');
        $sharedTask->activeAssignees()->attach($employee->id, [
            'assigned_by' => $employee->id,
            'assigned_at' => now(),
            'is_active' => true,
        ]);
        $expectedTaskCount = Task::query()
            ->where('assigned_to', $employee->id)
            ->orWhereHas('assignments', function ($query) use ($employee): void {
                $query->where('user_id', $employee->id)
                    ->where('is_active', true)
                    ->whereNull('unassigned_at');
            })
            ->count();

        $dashboardResponse = $this->actingAs($employee)->get(route('dashboard'));
        $dashboardResponse->assertSee(
            'href="'.route('tasks.index').'"',
            false,
        );
        $dashboardResponse->assertViewHas(
            'kpis',
            fn (array $kpis): bool => (int) $kpis[0]['value'] === $expectedTaskCount,
        );

        $taskListResponse = $this->get(route('tasks.index'));
        $taskListResponse->assertSee($assignedTask->title)
            ->assertDontSee($createdTask->title)
            ->assertSee($sharedTask->title)
            ->assertDontSee($otherTask->title);

        $this->get(route('tasks.index', ['assigned_to' => $otherEmployee->id]))
            ->assertDontSee($otherTask->title);
        $this->get(route('tasks.show', $otherTask))->assertForbidden();

        $exportResponse = $this->get(route('tasks.export'));
        $exportResponse->assertOk();
        $exportedCsv = $exportResponse->streamedContent();
        $this->assertStringContainsString($assignedTask->title, $exportedCsv);
        $this->assertStringNotContainsString($createdTask->title, $exportedCsv);
        $this->assertStringContainsString($sharedTask->title, $exportedCsv);
        $this->assertStringNotContainsString($otherTask->title, $exportedCsv);
    }

    public function test_team_leader_sees_all_tasks_in_their_department_only(): void
    {
        $teamLeader = User::query()->where('email', 'karthik@gmail.com')->firstOrFail();
        $employee = User::query()->where('email', 'anandhu@gmail.com')->firstOrFail();
        $otherDepartment = Department::query()->create([
            'company_id' => $teamLeader->company_id,
            'name' => 'Team Leader Visibility Test Department',
            'status' => 'active',
        ]);
        $otherDepartmentEmployee = User::factory()->create([
            'company_id' => $teamLeader->company_id,
            'department_id' => $otherDepartment->id,
            'role_id' => $employee->role_id,
        ]);

        $createdDepartmentTask = $this->createVisibilityTask(
            $teamLeader,
            Department::query()->findOrFail($teamLeader->department_id),
            $employee,
            'Team leader created department task',
        );
        $ownTask = $this->createVisibilityTask(
            User::query()->where('email', 'admin@example.com')->firstOrFail(),
            Department::query()->findOrFail($teamLeader->department_id),
            $teamLeader,
            'Team leader assigned own task',
        );
        $peerCreatedTask = $this->createVisibilityTask(
            User::query()->where('email', 'admin@example.com')->firstOrFail(),
            Department::query()->findOrFail($teamLeader->department_id),
            $employee,
            'Peer created department task',
        );
        $otherDepartmentTask = $this->createVisibilityTask(
            $teamLeader,
            $otherDepartment,
            $otherDepartmentEmployee,
            'Team leader task outside department',
        );

        $response = $this->actingAs($teamLeader)->get(route('tasks.index'));

        $response->assertSee($createdDepartmentTask->title)
            ->assertSee($ownTask->title)
            ->assertSee($peerCreatedTask->title)
            ->assertDontSee($otherDepartmentTask->title);
    }

    public function test_admin_and_ceo_can_see_tasks_assigned_to_any_employee(): void
    {
        $employee = User::query()->where('email', 'anandhu@gmail.com')->firstOrFail();
        $department = Department::query()->findOrFail($employee->department_id);
        $task = $this->createVisibilityTask($employee, $department, $employee, 'Organization-wide visible task');

        foreach (['admin@example.com', 'ceo@example.com'] as $email) {
            $user = User::query()->where('email', $email)->firstOrFail();

            $this->actingAs($user)->get(route('tasks.index'))->assertSee($task->title);
        }
    }

    public function test_employee_cannot_see_other_employees_or_organization_analytics(): void
    {
        $emp = User::query()->where('email', 'anandhu@gmail.com')->firstOrFail();
        $otherEmp = User::query()->where('email', 'rahul@example.com')->firstOrFail();

        // Attempting to query another employee's statistics via API
        $response = $this->actingAs($emp)->get(route('dashboard.data', [
            'employee_id' => $otherEmp->id,
            'company_id' => 1,
        ]));

        $response->assertOk();
        $data = $response->json('data');

        // Security check: Employee filter was forced to the authenticated employee
        $this->assertEquals('Employee', $response->json('role'));
        $this->assertEquals($emp->id, $data['selectedFilters']['employee_id']);
    }

    public function test_date_range_filters_affect_completion_and_task_statistics(): void
    {
        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();

        $response = $this->actingAs($admin)->get(route('dashboard.data', [
            'date_range' => 'today',
        ]));

        $response->assertOk();
        $data = $response->json('data');
        $this->assertEquals('today', $data['selectedFilters']['date_range']);
        $this->assertArrayHasKey('completionChart', $data);
        $this->assertArrayHasKey('statusDistribution', $data);
    }

    public function test_company_filter_applies_consistently_on_admin_dashboard(): void
    {
        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $company = Company::query()->where('name', 'UGI')->firstOrFail();

        $response = $this->actingAs($admin)->get(route('dashboard', [
            'company_id' => $company->id,
        ]));

        $response->assertOk();
        $response->assertViewHas('selectedFilters', function ($filters) use ($company) {
            return $filters['company_id'] === $company->id;
        });
    }

    private function createVisibilityTask(User $creator, Department $department, ?User $assignee, string $title): Task
    {
        return Task::create([
            'task_code' => Task::generateCode(),
            'title' => $title,
            'company_id' => $department->company_id,
            'department_id' => $department->id,
            'task_type' => 'process',
            'priority' => 'medium',
            'status' => 'assigned',
            'original_deadline' => today()->addDays(2)->toDateString(),
            'due_date' => today()->addDays(2)->toDateString(),
            'estimated_hours' => 2,
            'expected_output' => 'Visibility scope test task.',
            'created_by' => $creator->id,
            'assigned_to' => $assignee?->id,
        ]);
    }

    public function test_completion_rate_calculation_handles_zero_total_safely(): void
    {
        $service = app(DashboardService::class);
        $emptyQuery = Task::query()->whereRaw('1 = 0');

        $counts = $service->calculateTaskCounts($emptyQuery);

        $this->assertEquals(0, $counts['total']);
        $this->assertEquals(0.0, $counts['completion_rate']);
    }
}
