<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SubtaskManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Company $company;

    protected Department $department;

    protected User $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $this->actingAs($this->admin);
        $this->company = Company::query()->where('status', 'active')->firstOrFail();
        $this->department = Department::query()
            ->where('company_id', $this->company->id)
            ->where('status', 'active')
            ->firstOrFail();

        $this->employee = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'status' => 'active',
        ]);

        $employeeRole = Role::query()->where('name', 'Employee')->first();
        if ($employeeRole) {
            $this->employee->assignRole($employeeRole);
        }
    }

    // Store

    public function test_admin_can_create_subtask_via_json(): void
    {
        $task = $this->createTask();
        $startDate = now()->addDay()->toDateString();
        $dueDate = now()->addDays(5)->toDateString();
        $description = 'Build the requested feature.';

        $response = $this->postJson(
            route('subtasks.store', $task),
            [
                'title' => 'My first subtask',
                'description' => $description,
                'assigned_to' => $this->employee->id,
                'priority' => 'high',
                'start_date' => $startDate,
                'due_date' => $dueDate,
            ],
        );

        $response->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('subtask.description', $description)
            ->assertJsonPath('subtask.assigned_to.id', $this->employee->id)
            ->assertJsonPath('subtask.priority', 'high')
            ->assertJsonPath('subtask.start_date', $startDate)
            ->assertJsonPath('subtask.due_date', $dueDate);
        $this->assertDatabaseHas('subtasks', [
            'task_id' => $task->id,
            'title' => 'My first subtask',
            'description' => $description,
            'assigned_to' => $this->employee->id,
            'priority' => 'high',
            'start_date' => "{$startDate} 00:00:00",
            'due_date' => "{$dueDate} 00:00:00",
            'estimated_hours' => null,
            'created_by' => $this->admin->id,
        ]);
    }

    public function test_employee_cannot_create_subtasks_for_a_visible_task_they_do_not_manage(): void
    {
        $task = $this->createTask(['assigned_to' => $this->employee->id]);

        $this->actingAs($this->employee)
            ->postJson(route('subtasks.store', $task), ['title' => 'Unauthorized subtask'])
            ->assertForbidden();

        $this->actingAs($this->employee)
            ->get(route('tasks.show', $task))
            ->assertOk()
            ->assertDontSee('id="subtask-add-btn"', false);

        $this->assertDatabaseMissing('subtasks', ['title' => 'Unauthorized subtask']);
    }

    public function test_assigned_employee_can_create_subtasks_for_their_task(): void
    {
        $task = $this->createTask([
            'assigned_to' => $this->employee->id,
            'status' => 'assigned',
        ]);
        TaskAssignment::query()->create([
            'task_id' => $task->id,
            'user_id' => $this->employee->id,
            'assigned_by' => $this->admin->id,
            'reason' => 'Initial assignment',
            'assigned_at' => now(),
            'is_active' => true,
        ]);

        $this->actingAs($this->employee)
            ->get(route('tasks.show', $task))
            ->assertOk()
            ->assertSee('id="subtask-add-btn"', false);

        $this->actingAs($this->employee)
            ->postJson(route('subtasks.store', $task), ['title' => 'Employee-created subtask'])
            ->assertCreated()
            ->assertJsonPath('subtask.title', 'Employee-created subtask');

        $this->assertDatabaseHas('subtasks', [
            'task_id' => $task->id,
            'title' => 'Employee-created subtask',
            'created_by' => $this->employee->id,
        ]);
    }

    public function test_subtask_creation_records_created_by_as_authenticated_user(): void
    {
        $task = $this->createTask();
        $this->postJson(route('subtasks.store', $task), ['title' => 'Tracking creator']);
        $subtask = Subtask::query()->where('title', 'Tracking creator')->firstOrFail();
        $this->assertSame($this->admin->id, (int) $subtask->created_by);
    }

    public function test_subtask_creation_rejects_due_date_exceeding_parent_deadline(): void
    {
        $task = $this->createTask(['due_date' => now()->addDays(5)->toDateString()]);
        $response = $this->postJson(route('subtasks.store', $task), [
            'title' => 'Late subtask',
            'due_date' => now()->addDays(20)->toDateString(),
        ]);
        $response->assertUnprocessable();
        $this->assertDatabaseMissing('subtasks', ['title' => 'Late subtask']);
    }

    public function test_subtask_creation_validates_required_title(): void
    {
        $task = $this->createTask();
        $this->postJson(route('subtasks.store', $task), [])->assertUnprocessable();
    }

    // Show

    public function test_admin_can_fetch_subtask_details_as_json(): void
    {
        $task = $this->createTask();
        $subtask = $this->createSubtask($task, ['title' => 'Detail check', 'description' => 'Some notes']);
        $response = $this->getJson(route('subtasks.show', [$task, $subtask]));
        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('subtask.title', 'Detail check')
            ->assertJsonPath('subtask.description', 'Some notes');
    }

    public function test_subtask_show_returns_creator_and_assignee_separately(): void
    {
        $task = $this->createTask();
        $subtask = $this->createSubtask($task, ['assigned_to' => $this->employee->id]);
        $response = $this->getJson(route('subtasks.show', [$task, $subtask]));
        $response->assertOk()
            ->assertJsonPath('subtask.created_by.id', $this->admin->id)
            ->assertJsonPath('subtask.assigned_to.id', $this->employee->id);
    }

    public function test_subtask_show_404_when_subtask_does_not_belong_to_task(): void
    {
        $task1 = $this->createTask();
        $task2 = $this->createTask();
        $subtask = $this->createSubtask($task1);
        $this->getJson(route('subtasks.show', [$task2, $subtask]))->assertNotFound();
    }

    // Update

    public function test_admin_can_update_subtask_title_and_description(): void
    {
        $task = $this->createTask();
        $subtask = $this->createSubtask($task, ['title' => 'Old title']);
        $response = $this->patchJson(route('subtasks.update', [$task, $subtask]), [
            'title' => 'New title',
            'description' => 'Updated notes',
            'status' => 'in_progress',
        ]);
        $response->assertOk()->assertJsonPath('ok', true)->assertJsonPath('subtask.title', 'New title');
        $this->assertDatabaseHas('subtasks', ['id' => $subtask->id, 'title' => 'New title', 'description' => 'Updated notes']);
    }

    public function test_active_task_assignee_can_update_subtask_details(): void
    {
        $task = $this->createTask([
            'assigned_to' => $this->employee->id,
            'status' => 'assigned',
        ]);
        TaskAssignment::query()->create([
            'task_id' => $task->id,
            'user_id' => $this->employee->id,
            'assigned_by' => $this->admin->id,
            'reason' => 'Initial assignment',
            'assigned_at' => now(),
            'is_active' => true,
        ]);
        $subtask = $this->createSubtask($task, [
            'assigned_to' => $this->employee->id,
            'title' => 'Old subtask title',
        ]);

        $this->actingAs($this->employee)
            ->patchJson(route('subtasks.update', [$task, $subtask]), [
                'title' => 'Updated subtask title',
                'description' => 'Updated details',
                'assigned_to' => $this->employee->id,
                'priority' => 'high',
                'status' => 'todo',
                'start_date' => null,
                'due_date' => null,
            ])
            ->assertOk()
            ->assertJsonPath('subtask.title', 'Updated subtask title')
            ->assertJsonPath('subtask.description', 'Updated details');

        $this->assertDatabaseHas('subtasks', [
            'id' => $subtask->id,
            'title' => 'Updated subtask title',
            'description' => 'Updated details',
            'assigned_to' => $this->employee->id,
            'priority' => 'high',
        ]);
    }

    public function test_active_task_assignee_cannot_reassign_a_subtask(): void
    {
        $task = $this->createTask([
            'assigned_to' => $this->employee->id,
            'status' => 'assigned',
        ]);
        TaskAssignment::query()->create([
            'task_id' => $task->id,
            'user_id' => $this->employee->id,
            'assigned_by' => $this->admin->id,
            'reason' => 'Initial assignment',
            'assigned_at' => now(),
            'is_active' => true,
        ]);
        $otherEmployee = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'status' => 'active',
        ]);
        $subtask = $this->createSubtask($task, ['assigned_to' => $this->employee->id]);

        $this->actingAs($this->employee)
            ->patchJson(route('subtasks.update', [$task, $subtask]), [
                'assigned_to' => $otherEmployee->id,
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('subtasks', [
            'id' => $subtask->id,
            'assigned_to' => $this->employee->id,
        ]);
    }

    public function test_status_actions_update_subtask_status_and_completion_progress(): void
    {
        $task = $this->createTask();
        $subtask = $this->createSubtask($task);

        foreach ([
            ['status' => 'completed', 'is_completed' => true, 'progress_percentage' => 100],
            ['status' => 'in_progress', 'is_completed' => false, 'progress_percentage' => 0],
            ['status' => 'on_hold', 'is_completed' => false, 'progress_percentage' => 0],
        ] as $expected) {
            $response = $this->patchJson(route('subtasks.update', [$task, $subtask]), [
                'status' => $expected['status'],
            ]);

            $response->assertOk()
                ->assertJsonPath('subtask.status', $expected['status'])
                ->assertJsonPath('subtask.is_completed', $expected['is_completed'])
                ->assertJsonPath('subtask.progress_percentage', $expected['progress_percentage']);
        }
    }

    public function test_update_rejects_due_date_exceeding_parent_deadline(): void
    {
        $task = $this->createTask(['due_date' => now()->addDays(5)->toDateString()]);
        $subtask = $this->createSubtask($task);
        $this->patchJson(route('subtasks.update', [$task, $subtask]), [
            'due_date' => now()->addDays(30)->toDateString(),
        ])->assertUnprocessable();
    }

    // Toggle

    public function test_toggle_marks_subtask_complete_and_back(): void
    {
        $task = $this->createTask();
        $subtask = $this->createSubtask($task);
        $this->assertFalse($subtask->is_completed);

        $this->patchJson(route('subtasks.toggle', [$task, $subtask]))
            ->assertOk()
            ->assertJsonPath('subtask.is_completed', true);

        $this->assertTrue($subtask->fresh()->is_completed);

        $this->patchJson(route('subtasks.toggle', [$task, $subtask]))
            ->assertOk()
            ->assertJsonPath('subtask.is_completed', false);

        $this->assertFalse($subtask->fresh()->is_completed);
    }

    // Delete

    public function test_admin_can_delete_a_subtask(): void
    {
        $task = $this->createTask();
        $subtask = $this->createSubtask($task, ['title' => 'To be deleted']);
        $this->deleteJson(route('subtasks.destroy', [$task, $subtask]))->assertOk()->assertJsonPath('ok', true);
        $this->assertDatabaseMissing('subtasks', ['id' => $subtask->id]);
    }

    public function test_assigned_employee_can_delete_a_subtask(): void
    {
        $task = $this->createTask();
        TaskAssignment::query()->create([
            'task_id' => $task->id,
            'user_id' => $this->employee->id,
            'is_current' => true,
            'assigned_at' => now(),
        ]);
        $subtask = $this->createSubtask($task, [
            'title' => 'Employee subtask',
            'created_by' => $this->employee->id,
            'assigned_to' => $this->employee->id,
        ]);

        $this->actingAs($this->employee)
            ->deleteJson(route('subtasks.destroy', [$task, $subtask]))
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseMissing('subtasks', ['id' => $subtask->id]);
    }

    // Bulk store

    public function test_admin_can_bulk_create_subtasks(): void
    {
        $task = $this->createTask();
        $response = $this->postJson(route('subtasks.bulk-store', $task), [
            'subtasks' => [
                ['title' => 'Bulk subtask 1'],
                ['title' => 'Bulk subtask 2', 'priority' => 'critical'],
            ],
        ]);
        $response->assertCreated()->assertJsonPath('ok', true);
        $this->assertDatabaseHas('subtasks', ['task_id' => $task->id, 'title' => 'Bulk subtask 1']);
        $this->assertDatabaseHas('subtasks', ['task_id' => $task->id, 'title' => 'Bulk subtask 2']);
    }

    // Progress

    public function test_parent_task_progress_updates_when_subtask_is_toggled(): void
    {
        $task = $this->createTask();
        $subtask = $this->createSubtask($task);
        $this->assertSame(0, (int) $task->fresh()->progress_percentage);
        $this->patchJson(route('subtasks.toggle', [$task, $subtask]))->assertOk();
        $this->assertSame(100, (int) $task->fresh()->progress_percentage);
    }

    // Helpers

    private function createTask(array $attributes = []): Task
    {
        return Task::create(array_merge([
            'task_code' => Task::generateCode(),
            'title' => 'Feature test task',
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'task_type' => 'process',
            'priority' => 'medium',
            'estimated_hours' => 4,
            'due_date' => now()->addDays(30)->toDateString(),
            'original_deadline' => now()->addDays(30)->toDateString(),
            'expected_output' => 'Test output.',
            'created_by' => $this->admin->id,
        ], $attributes));
    }

    private function createSubtask(Task $task, array $attributes = []): Subtask
    {
        return $task->subtasks()->create(array_merge([
            'title' => 'Test subtask',
            'created_by' => $this->admin->id,
            'priority' => 'medium',
            'status' => 'todo',
        ], $attributes));
    }
}
