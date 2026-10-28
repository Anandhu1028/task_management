<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\DeadlineExtensionRequest;
use App\Models\Department;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DesktopApiTaskTest extends TestCase
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
        $this->department = Department::query()
            ->where('company_id', $this->company->id)
            ->where('status', 'active')
            ->firstOrFail();
    }

    public function test_authorized_user_can_list_and_search_visible_tasks(): void
    {
        $visible = $this->createTask(['title' => 'Visible desktop task']);
        $this->createTask(['title' => 'Other desktop task']);

        $response = $this->actingAsDesktop($this->admin)
            ->getJson('/api/tasks?search=Visible&per_page=25');

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $visible->id)
            ->assertJsonPath('data.0.title', 'Visible desktop task')
            ->assertJsonStructure(['counts' => ['total', 'overdue_count', 'at_risk_count']]);
    }

    public function test_awaiting_assignment_filter_includes_all_unassigned_workflow_statuses(): void
    {
        $notified = $this->createTask([
            'title' => 'Quick-filter fixture notified task',
            'status' => 'notified',
        ]);
        $unassigned = $this->createTask([
            'title' => 'Quick-filter fixture unassigned task',
            'status' => 'unassigned',
        ]);
        $assigned = $this->createTask([
            'title' => 'Quick-filter fixture assigned task',
            'status' => 'assigned',
        ]);
        $this->createTask([
            'title' => 'Quick-filter fixture in progress task',
            'status' => 'in_progress',
        ]);

        $response = $this->actingAsDesktop($this->admin)
            ->getJson('/api/tasks?search=Quick-filter%20fixture&status_group=awaiting_assignment');

        $response->assertOk()->assertJsonPath('meta.total', 3);
        $this->assertEqualsCanonicalizing(
            [$notified->id, $unassigned->id, $assigned->id],
            collect($response->json('data'))->pluck('id')->all(),
        );
    }

    public function test_task_summary_marks_due_today_in_the_application_timezone(): void
    {
        $task = $this->createTask([
            'title' => 'Due today desktop task',
            'due_date' => today()->toDateString(),
            'original_deadline' => today()->toDateString(),
        ]);

        $this->actingAsDesktop($this->admin)
            ->getJson('/api/tasks?search=Due%20today%20desktop%20task')
            ->assertOk()
            ->assertJsonPath('data.0.id', $task->id)
            ->assertJsonPath('data.0.is_due_today', true)
            ->assertJsonPath('data.0.is_overdue', false);
    }

    public function test_employee_only_sees_assigned_tasks(): void
    {
        $employee = $this->createEmployee();
        $assigned = $this->createTask(['title' => 'Assigned to employee']);
        TaskAssignment::create([
            'task_id' => $assigned->id,
            'user_id' => $employee->id,
            'assigned_by' => $this->admin->id,
            'reason' => 'Test assignment',
            'assigned_at' => now(),
            'is_active' => true,
        ]);
        $assigned->update(['assigned_to' => $employee->id, 'status' => 'assigned']);
        $this->createTask(['title' => 'Hidden from employee']);

        $response = $this->actingAsDesktop($employee)->getJson('/api/tasks');

        $response->assertOk();
        $titles = collect($response->json('data'))->pluck('title');
        $this->assertTrue($titles->contains('Assigned to employee'));
        $this->assertFalse($titles->contains('Hidden from employee'));
    }

    public function test_user_without_task_view_permission_cannot_list_tasks(): void
    {
        $employee = $this->createEmployee();
        Role::query()->where('name', 'Employee')->firstOrFail()->revokePermissionTo('tasks.view');
        $employee->refresh();

        $this->actingAsDesktop($employee)
            ->getJson('/api/tasks')
            ->assertForbidden();
    }

    public function test_authorized_user_can_create_and_view_a_task(): void
    {
        $poc = $this->createEmployee();

        $response = $this->actingAsDesktop($this->admin)->postJson('/api/tasks', [
            'title' => 'Desktop created task',
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'task_type' => 'process',
            'priority' => 'high',
            'poc_ids' => [$poc->id],
            'estimated_hours' => 3.5,
            'expected_output' => 'A completed desktop task.',
            'description' => 'Created from the desktop API.',
            'original_deadline' => now()->addDays(5)->toDateString(),
        ]);

        $response->assertCreated()
            ->assertJsonPath('task.title', 'Desktop created task')
            ->assertJsonPath('task.status', 'notified')
            ->assertJsonPath('task.priority', 'high');

        $taskId = $response->json('task.id');
        $this->assertDatabaseHas('tasks', [
            'id' => $taskId,
            'title' => 'Desktop created task',
            'created_by' => $this->admin->id,
        ]);

        $this->actingAsDesktop($this->admin)
            ->getJson('/api/tasks/'.$taskId)
            ->assertOk()
            ->assertJsonPath('task.id', $taskId)
            ->assertJsonPath('task.permissions.accept', false);
    }

    public function test_task_create_validation_errors_are_returned(): void
    {
        $this->actingAsDesktop($this->admin)
            ->postJson('/api/tasks', [
                'title' => '',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title', 'company_id', 'department_id', 'poc_ids']);
    }

    public function test_authorized_user_can_update_a_task(): void
    {
        $task = $this->createTask(['title' => 'Before update']);
        $poc = $this->createEmployee();
        $task->pocs()->sync([$poc->id]);

        $this->actingAsDesktop($this->admin)
            ->putJson('/api/tasks/'.$task->id, [
                'title' => 'After update',
                'company_id' => $this->company->id,
                'department_id' => $this->department->id,
                'task_type' => 'process',
                'priority' => 'critical',
                'poc_ids' => [$poc->id],
                'estimated_hours' => 4,
                'expected_output' => 'Updated output',
                'description' => 'Updated from desktop.',
            ])
            ->assertOk()
            ->assertJsonPath('task.title', 'After update')
            ->assertJsonPath('task.priority', 'critical');

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'title' => 'After update',
            'priority' => 'critical',
        ]);
    }

    public function test_authorized_desktop_task_edit_reassigns_and_persists_assignees(): void
    {
        $previousAssignee = $this->createEmployee();
        $newAssignee = $this->createEmployee();
        $task = $this->createTask([
            'status' => 'assigned',
            'assigned_to' => $previousAssignee->id,
        ]);
        TaskAssignment::create([
            'task_id' => $task->id,
            'user_id' => $previousAssignee->id,
            'assigned_by' => $this->admin->id,
            'reason' => 'Initial assignment',
            'assigned_at' => now(),
            'is_active' => true,
        ]);

        $this->actingAsDesktop($this->admin)
            ->putJson('/api/tasks/'.$task->id, [
                'title' => $task->title,
                'company_id' => $this->company->id,
                'department_id' => $this->department->id,
                'task_type' => 'process',
                'priority' => 'medium',
                'assigned_to' => [$newAssignee->id],
                'poc_ids' => [],
                'estimated_hours' => 2,
                'expected_output' => 'A completed desktop workflow deliverable.',
            ])
            ->assertOk()
            ->assertJsonPath('task.assignees.0.id', $newAssignee->id);

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'assigned_to' => $newAssignee->id,
        ]);
        $this->assertDatabaseHas('task_assignments', [
            'task_id' => $task->id,
            'user_id' => $previousAssignee->id,
            'is_active' => false,
        ]);
        $this->assertDatabaseHas('task_assignments', [
            'task_id' => $task->id,
            'user_id' => $newAssignee->id,
            'is_active' => true,
        ]);
    }

    public function test_desktop_assignee_can_request_deadline_extension_and_view_request(): void
    {
        $employee = $this->createEmployee();
        $task = $this->createTask([
            'status' => 'in_progress',
            'assigned_to' => $employee->id,
        ]);
        TaskAssignment::create([
            'task_id' => $task->id,
            'user_id' => $employee->id,
            'assigned_by' => $this->admin->id,
            'reason' => 'Initial assignment',
            'assigned_at' => now(),
            'is_active' => true,
        ]);
        $requestedDeadline = now()->addDays(20)->toDateString();

        $requestResponse = $this->actingAsDesktop($employee)
            ->postJson('/api/tasks/'.$task->id.'/deadline-extension-requests', [
                'requested_deadline' => $requestedDeadline,
                'reason' => 'A required dependency is delayed.',
            ]);

        $requestResponse->assertCreated()
            ->assertJsonPath('request.status', DeadlineExtensionRequest::STATUS_PENDING);
        $requestId = $requestResponse->json('request.id');
        $this->assertDatabaseHas('deadline_extension_requests', [
            'id' => $requestId,
            'task_id' => $task->id,
            'requested_by' => $employee->id,
            'status' => DeadlineExtensionRequest::STATUS_PENDING,
        ]);
        $this->actingAsDesktop($employee)
            ->getJson('/api/tasks/'.$task->id)
            ->assertOk()
            ->assertJsonPath('task.deadline_extension_requests.0.id', $requestId)
            ->assertJsonPath('task.permissions.request_deadline_extension', false);

    }

    public function test_desktop_admin_can_approve_deadline_extension_and_persist_new_deadline(): void
    {
        $employee = $this->createEmployee();
        $task = $this->createTask([
            'status' => 'in_progress',
            'assigned_to' => $employee->id,
        ]);
        $requestedDeadline = now()->addDays(20)->toDateString();
        $extensionRequest = $task->deadlineExtensionRequests()->create([
            'requested_by' => $employee->id,
            'task_title' => $task->title,
            'current_deadline' => $task->due_date->toDateString(),
            'requested_deadline' => $requestedDeadline,
            'reason' => 'A required dependency is delayed.',
            'status' => DeadlineExtensionRequest::STATUS_PENDING,
        ]);

        $this->actingAsDesktop($this->admin)
            ->getJson('/api/tasks/'.$task->id)
            ->assertOk()
            ->assertJsonPath('task.permissions.review_deadline_extensions', true)
            ->assertJsonPath('task.deadline_extension_requests.0.id', $extensionRequest->id);

        $this->actingAsDesktop($this->admin)
            ->postJson('/api/tasks/'.$task->id.'/deadline-extension-requests/'.$extensionRequest->id.'/review', [
                'decision' => 'approve',
            ])
            ->assertOk()
            ->assertJsonPath('request.status', DeadlineExtensionRequest::STATUS_APPROVED);

        $this->assertDatabaseHas('deadline_extension_requests', [
            'id' => $extensionRequest->id,
            'reviewed_by' => $this->admin->id,
            'status' => DeadlineExtensionRequest::STATUS_APPROVED,
        ]);
        $this->assertSame($requestedDeadline, $task->fresh()->due_date->toDateString());
    }

    public function test_poc_can_accept_a_notified_task(): void
    {
        $poc = $this->createEmployee();
        $task = $this->createTask(['status' => 'notified', 'assigned_to' => null]);
        $task->pocs()->sync([$poc->id]);

        $this->actingAsDesktop($poc)
            ->postJson('/api/tasks/'.$task->id.'/accept')
            ->assertOk()
            ->assertJsonPath('task.status', 'unassigned');

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'status' => 'unassigned',
        ]);
    }

    public function test_assignee_can_start_work_and_add_a_subtask(): void
    {
        $employee = $this->createEmployee();
        $task = $this->createTask([
            'status' => 'assigned',
            'assigned_to' => $employee->id,
            'start_date' => now()->toDateString(),
            'due_date' => now()->addDays(10)->toDateString(),
        ]);
        TaskAssignment::create([
            'task_id' => $task->id,
            'user_id' => $employee->id,
            'assigned_by' => $this->admin->id,
            'reason' => 'Initial assignment',
            'assigned_at' => now(),
            'is_active' => true,
        ]);

        $this->actingAsDesktop($employee)
            ->postJson('/api/tasks/'.$task->id.'/start', [
                'note' => 'Starting the assigned work.',
                'start_date' => now()->toDateString(),
            ])
            ->assertOk()
            ->assertJsonPath('task.status', 'in_progress');

        $this->actingAsDesktop($employee)
            ->postJson('/api/tasks/'.$task->id.'/subtasks', [
                'title' => 'Desktop subtask',
                'priority' => 'medium',
            ])
            ->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('subtask.title', 'Desktop subtask');

        $this->assertDatabaseHas('subtasks', [
            'task_id' => $task->id,
            'title' => 'Desktop subtask',
            'priority' => 'medium',
        ]);

        $this->actingAsDesktop($employee)
            ->postJson('/api/tasks/'.$task->id.'/work-updates', [
                'note' => 'Progress note from desktop.',
                'update_date' => now()->toDateString(),
            ])
            ->assertOk()
            ->assertJsonPath('task.id', $task->id);

        $this->assertDatabaseHas('task_status_histories', [
            'task_id' => $task->id,
            'changed_by' => $employee->id,
            'event_type' => 'work_update',
            'remarks' => 'Progress note from desktop.',
        ]);
    }

    public function test_desktop_meta_returns_existing_status_and_priority_values(): void
    {
        $this->actingAsDesktop($this->admin)
            ->getJson('/api/desktop/meta')
            ->assertOk()
            ->assertJsonPath('statuses.0.value', 'notified')
            ->assertJsonFragment(['value' => 'critical'])
            ->assertJsonFragment(['value' => 'process']);
    }

    public function test_existing_web_task_index_still_works(): void
    {
        $this->actingAs($this->admin)
            ->get(route('tasks.index'))
            ->assertOk()
            ->assertSee('All Tasks');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createTask(array $attributes = []): Task
    {
        return Task::create(array_merge([
            'task_code' => Task::generateCode(),
            'title' => 'Desktop workflow task',
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'task_type' => 'process',
            'priority' => 'medium',
            'status' => 'unassigned',
            'estimated_hours' => 2,
            'due_date' => now()->addDays(10)->toDateString(),
            'original_deadline' => now()->addDays(10)->toDateString(),
            'expected_output' => 'A completed desktop workflow deliverable.',
            'created_by' => $this->admin->id,
        ], $attributes));
    }

    private function createEmployee(): User
    {
        $role = Role::query()->where('name', 'Employee')->firstOrFail();
        $user = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'role_id' => $role->id,
            'status' => 'active',
            'password' => 'test-password',
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function actingAsDesktop(User $user): self
    {
        $token = $user->createToken('desktop')->plainTextToken;

        return $this->withToken($token);
    }
}
