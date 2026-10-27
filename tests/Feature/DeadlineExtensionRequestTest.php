<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\DeadlineExtensionRequest;
use App\Models\Department;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\User;
use App\Notifications\TaskLifecycleNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DeadlineExtensionRequestTest extends TestCase
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

    public function test_assigned_employee_can_submit_request_and_reviewers_are_notified(): void
    {
        $employee = $this->createUserWithRole('Employee');
        $poc = $this->createUserWithRole('Employee');
        $manager = $this->createUserWithRole('Manager');
        $teamLeader = $this->createUserWithRole('Team Leader');
        $ceo = $this->createUserWithRole('CEO');
        $task = $this->createAssignedTask($employee);
        $task->pocs()->sync([$poc->id]);

        $currentDeadline = $task->due_date->toDateString();
        $requestedDeadline = now()->addDays(20)->toDateString();

        $this->actingAs($employee)
            ->get(route('tasks.index'))
            ->assertOk()
            ->assertSee('Request Deadline Extension');

        $this->actingAs($employee)
            ->post(route('tasks.deadline-extension-requests.store', $task), [
                'requested_deadline' => $requestedDeadline,
                'reason' => 'A dependency needs additional time.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('deadline_extension_requests', [
            'task_id' => $task->id,
            'requested_by' => $employee->id,
            'task_title' => $task->title,
            'reason' => 'A dependency needs additional time.',
            'status' => DeadlineExtensionRequest::STATUS_PENDING,
        ]);
        $savedRequest = $task->deadlineExtensionRequests()->firstOrFail();
        $this->assertSame($currentDeadline, $savedRequest->current_deadline->toDateString());
        $this->assertSame($requestedDeadline, $savedRequest->requested_deadline->toDateString());
        $this->assertDatabaseHas('task_status_histories', [
            'task_id' => $task->id,
            'changed_by' => $employee->id,
            'event_type' => 'deadline_extension_requested',
        ]);

        $managerNotification = $manager->notifications()->latest()->firstOrFail();
        $this->assertSame('Deadline extension requested', $managerNotification->data['title']);
        $this->assertStringContainsString(
            now()->parse($requestedDeadline)->format('M d, Y'),
            $managerNotification->data['body'],
        );

        foreach ([$this->admin, $poc, $manager, $teamLeader, $ceo] as $reviewer) {
            $this->assertDatabaseHas('notifications', [
                'notifiable_type' => User::class,
                'notifiable_id' => $reviewer->id,
                'type' => TaskLifecycleNotification::class,
            ]);
        }

        $this->actingAs($this->admin)
            ->get(route('tasks.show', $task))
            ->assertOk()
            ->assertSee('requested a deadline extension')
            ->assertSee('Extension Requested');

        $this->assertSame($currentDeadline, $task->fresh()->due_date->toDateString());
        $this->actingAs($employee)
            ->get(route('tasks.index'))
            ->assertOk()
            ->assertDontSee('Request Deadline Extension');
    }

    public function test_non_assignee_cannot_submit_a_deadline_extension_request(): void
    {
        $employee = $this->createUserWithRole('Employee');
        $task = $this->createAssignedTask($this->createUserWithRole('Employee'));

        $this->actingAs($employee)
            ->post(route('tasks.deadline-extension-requests.store', $task), [
                'requested_deadline' => now()->addDays(20)->toDateString(),
                'reason' => 'Need more time.',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('deadline_extension_requests', 0);
    }

    public function test_authorized_reviewer_approves_request_and_updates_only_current_deadline(): void
    {
        $employee = $this->createUserWithRole('Employee');
        $task = $this->createAssignedTask($employee);
        $extensionRequest = $this->createExtensionRequest($task, $employee);

        $this->actingAs($this->admin)
            ->post(route('tasks.deadline-extension-requests.review', [$task, $extensionRequest]), [
                'decision' => 'approve',
            ])
            ->assertRedirect();

        $extensionRequest->refresh();
        $task->refresh();
        $this->assertSame(DeadlineExtensionRequest::STATUS_APPROVED, $extensionRequest->status);
        $this->assertSame($extensionRequest->requested_deadline->toDateString(), $task->due_date->toDateString());
        $this->assertSame($extensionRequest->current_deadline->toDateString(), $task->original_deadline->toDateString());
        $this->assertDatabaseHas('task_status_histories', [
            'task_id' => $task->id,
            'changed_by' => $this->admin->id,
            'event_type' => 'deadline_extension_approved',
        ]);
        $this->assertSame(
            'Deadline extension approved',
            $employee->notifications()->latest()->firstOrFail()->data['title'],
        );

        $this->actingAs($employee)
            ->get(route('tasks.show', $task))
            ->assertOk()
            ->assertSee('approved the deadline extension')
            ->assertSee('Extension Approved');

        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => User::class,
            'notifiable_id' => $employee->id,
            'type' => TaskLifecycleNotification::class,
        ]);
    }

    public function test_rejected_request_does_not_change_task_deadline(): void
    {
        $employee = $this->createUserWithRole('Employee');
        $task = $this->createAssignedTask($employee);
        $extensionRequest = $this->createExtensionRequest($task, $employee);
        $currentDeadline = $task->due_date->toDateString();

        $this->actingAs($this->admin)
            ->post(route('tasks.deadline-extension-requests.review', [$task, $extensionRequest]), [
                'decision' => 'reject',
                'review_note' => 'The current schedule is retained.',
            ])
            ->assertRedirect();

        $extensionRequest->refresh();
        $this->assertSame(DeadlineExtensionRequest::STATUS_REJECTED, $extensionRequest->status);
        $this->assertSame('The current schedule is retained.', $extensionRequest->review_note);
        $this->assertDatabaseHas('task_status_histories', [
            'task_id' => $task->id,
            'changed_by' => $this->admin->id,
            'event_type' => 'deadline_extension_rejected',
        ]);
        $this->assertSame(
            'Deadline extension rejected',
            $employee->notifications()->latest()->firstOrFail()->data['title'],
        );
        $this->assertSame($currentDeadline, $task->fresh()->due_date->toDateString());
    }

    public function test_manager_can_view_and_review_requests_for_their_company(): void
    {
        $employee = $this->createUserWithRole('Employee');
        $manager = $this->createUserWithRole('Manager');
        $task = $this->createAssignedTask($employee);
        $extensionRequest = $this->createExtensionRequest($task, $employee);

        $this->actingAs($manager)
            ->get(route('tasks.index'))
            ->assertOk()
            ->assertSee($task->title);

        $this->actingAs($manager)
            ->get(route('tasks.show', $task))
            ->assertOk()
            ->assertSee('data-activity-tab="timeline"', false)
            ->assertSee('data-activity-tab="subtasks"', false)
            ->assertSee('data-activity-tab="requests"', false)
            ->assertSee($extensionRequest->reason)
            ->assertSee('Approve');

        $this->actingAs($manager)
            ->post(route('tasks.deadline-extension-requests.review', [$task, $extensionRequest]), [
                'decision' => 'approve',
            ])
            ->assertRedirect();

        $this->assertSame(DeadlineExtensionRequest::STATUS_APPROVED, $extensionRequest->fresh()->status);
    }

    public function test_unrelated_employee_cannot_review_an_extension_request(): void
    {
        $employee = $this->createUserWithRole('Employee');
        $unrelatedEmployee = $this->createUserWithRole('Employee');
        $task = $this->createAssignedTask($employee);
        $extensionRequest = $this->createExtensionRequest($task, $employee);

        $response = $this->actingAs($unrelatedEmployee)
            ->withHeader('Accept', 'application/json')
            ->post(route('tasks.deadline-extension-requests.review', [$task, $extensionRequest]), [
                'decision' => 'approve',
            ]);
        $response->assertForbidden();

        $this->assertSame(DeadlineExtensionRequest::STATUS_PENDING, $extensionRequest->fresh()->status);
    }

    public function test_reviewer_cannot_approve_stale_request_or_review_another_tasks_request(): void
    {
        $employee = $this->createUserWithRole('Employee');
        $task = $this->createAssignedTask($employee);
        $extensionRequest = $this->createExtensionRequest($task, $employee);
        $otherTask = $this->createAssignedTask($employee);

        $task->update(['due_date' => now()->addDays(12)->toDateString()]);

        $this->actingAs($this->admin)
            ->post(route('tasks.deadline-extension-requests.review', [$task, $extensionRequest]), [
                'decision' => 'approve',
            ])
            ->assertSessionHasErrors('decision');

        $this->post(route('tasks.deadline-extension-requests.review', [$otherTask, $extensionRequest]), [
            'decision' => 'approve',
        ])->assertNotFound();

        $this->assertSame(DeadlineExtensionRequest::STATUS_PENDING, $extensionRequest->fresh()->status);
    }

    public function test_manager_cannot_view_requests_from_another_company(): void
    {
        $otherCompany = Company::create([
            'name' => 'Other Company',
            'description' => null,
            'status' => 'active',
        ]);
        $otherDepartment = Department::create([
            'company_id' => $otherCompany->id,
            'name' => 'Other Department',
            'designation' => 'Team Member',
            'status' => 'active',
        ]);
        $employee = $this->createUserWithRole('Employee', $otherCompany, $otherDepartment);
        $manager = $this->createUserWithRole('Manager');
        $task = $this->createAssignedTask($employee, $otherCompany, $otherDepartment);
        $extensionRequest = $this->createExtensionRequest($task, $employee);

        $this->actingAs($manager)
            ->get(route('tasks.show', $task))
            ->assertForbidden();

        $this->actingAs($manager)
            ->post(route('tasks.deadline-extension-requests.review', [$task, $extensionRequest]), [
                'decision' => 'approve',
            ])
            ->assertForbidden();

        $this->assertSame(DeadlineExtensionRequest::STATUS_PENDING, $extensionRequest->fresh()->status);
    }

    private function createAssignedTask(
        User $employee,
        ?Company $company = null,
        ?Department $department = null,
    ): Task {
        $deadline = now()->addDays(5)->toDateString();
        $company ??= $this->company;
        $department ??= $this->department;
        $task = Task::create([
            'task_code' => Task::generateCode(),
            'title' => 'Extension workflow task',
            'company_id' => $company->id,
            'department_id' => $department->id,
            'task_type' => 'process',
            'priority' => 'medium',
            'status' => 'assigned',
            'assigned_to' => $employee->id,
            'original_deadline' => $deadline,
            'due_date' => $deadline,
            'estimated_hours' => 3,
            'expected_output' => 'Test deliverable',
            'created_by' => $this->admin->id,
        ]);
        TaskAssignment::create([
            'task_id' => $task->id,
            'user_id' => $employee->id,
            'assigned_by' => $this->admin->id,
            'reason' => 'Initial assignment',
            'assigned_at' => now(),
            'is_active' => true,
        ]);

        return $task->load(['activeAssignees', 'pocs']);
    }

    private function createExtensionRequest(Task $task, User $employee): DeadlineExtensionRequest
    {
        return $task->deadlineExtensionRequests()->create([
            'requested_by' => $employee->id,
            'task_title' => $task->title,
            'current_deadline' => $task->due_date->toDateString(),
            'requested_deadline' => now()->addDays(15)->toDateString(),
            'reason' => 'A dependency needs additional time.',
            'status' => DeadlineExtensionRequest::STATUS_PENDING,
        ]);
    }

    private function createUserWithRole(
        string $roleName,
        ?Company $company = null,
        ?Department $department = null,
    ): User {
        $role = Role::query()->firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        $viewTasks = Permission::query()->firstOrCreate(['name' => 'tasks.view', 'guard_name' => 'web']);
        $role->givePermissionTo($viewTasks);
        $company ??= $this->company;
        $department ??= $this->department;

        $user = User::factory()->create([
            'company_id' => $company->id,
            'department_id' => $department->id,
            'role_id' => $role->id,
            'status' => 'active',
        ]);
        $user->assignRole($role);

        return $user;
    }
}
