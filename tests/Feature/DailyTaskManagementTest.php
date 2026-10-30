<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\DailyTask;
use App\Models\Department;
use App\Models\Milestone;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DailyTaskManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Company $company;

    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->company = Company::factory()->create();
        $this->department = Department::factory()->create(['company_id' => $this->company->id]);
        $this->admin = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
        ]);
        $this->admin->assignRole('Admin');
    }

    public function test_employee_can_create_a_daily_task_for_themselves_on_an_assigned_task(): void
    {
        $employee = $this->createEmployee();
        $task = $this->createAssignedTask($employee);
        $workDate = today()->toDateString();

        $response = $this->actingAs($employee)->post(route('daily-tasks.store', $task), [
            'title' => 'Review account documentation',
            'work_date' => $workDate,
            'planned_start_time' => '09:00',
            'planned_end_time' => '10:30',
            'priority' => 'high',
        ]);

        $dailyTask = DailyTask::query()->where('task_id', $task->id)->firstOrFail();
        $response->assertRedirect(route('tasks.show', $task).'#panel-daily-tasks');
        $this->assertDatabaseHas('daily_tasks', [
            'id' => $dailyTask->id,
            'task_id' => $task->id,
            'assigned_to' => $employee->id,
            'status' => 'pending',
            'estimated_minutes' => 90,
        ]);
        $this->assertDatabaseHas('task_status_histories', [
            'daily_task_id' => $dailyTask->id,
            'event_type' => 'daily_task_created',
        ]);
    }

    public function test_employee_can_create_and_update_daily_tasks_from_the_task_detail_and_see_timeline_updates(): void
    {
        $employee = $this->createEmployee();
        $task = $this->createAssignedTask($employee);
        $returnUrl = route('tasks.show', $task).'#panel-daily-tasks';

        $response = $this->actingAs($employee)
            ->get(route('tasks.show', $task))
            ->assertSee('Add Daily Task')
            ->assertSee('id="daily-task-create-dialog"', false)
            ->assertSee('name="work_date"', false)
            ->assertSee('name="title"', false)
            ->assertSee('value="'.$employee->name.'" readonly', false);
        $html = $response->getContent();
        $dialogStart = strpos($html, 'id="daily-task-create-dialog"');
        $dialogEnd = strpos($html, '</form>', $dialogStart);
        $this->assertNotFalse($dialogStart);
        $this->assertNotFalse($dialogEnd);
        $dialog = substr($html, $dialogStart, $dialogEnd - $dialogStart);
        $this->assertStringNotContainsString('name="assigned_to"', $dialog);

        $this->post(route('daily-tasks.store', $task), [
            'title' => 'Reconcile support queue',
            'work_date' => today()->toDateString(),
            'quick_entry' => '1',
            'assigned_to' => $this->admin->id,
            'priority' => 'medium',
        ])->assertRedirect($returnUrl);

        $dailyTask = DailyTask::query()->where('task_id', $task->id)->firstOrFail();
        $this->assertDatabaseHas('daily_tasks', [
            'id' => $dailyTask->id,
            'assigned_to' => $employee->id,
            'estimated_minutes' => null,
        ]);
        $this->assertNotNull($dailyTask->planned_start_time);
        $this->assertNotNull($dailyTask->planned_end_time);

        $this->post(route('daily-tasks.action', [$task, $dailyTask, 'start']))
            ->assertRedirect($returnUrl);
        $this->post(route('daily-tasks.action', [$task, $dailyTask, 'hold']))
            ->assertRedirect($returnUrl);
        $this->get(route('tasks.show', $task))
            ->assertSee('Unhold')
            ->assertSee('Status: On Hold', false);
        $this->post(route('daily-tasks.action', [$task, $dailyTask, 'resume']))
            ->assertRedirect($returnUrl);

        $this->assertDatabaseHas('daily_tasks', [
            'id' => $dailyTask->id,
            'status' => 'in_progress',
        ]);

        $this->get(route('tasks.show', $task))
            ->assertSee('Reconcile support queue')
            ->assertSee('On Hold')
            ->assertSee('Daily Task: Reconcile support queue', false)
            ->assertSee('Status: On Hold', false)
            ->assertSee('Daily Task Update');
    }

    public function test_standalone_daily_task_pages_redirect_to_the_task_detail_or_task_list(): void
    {
        $employee = $this->createEmployee();
        $task = $this->createAssignedTask($employee);
        $dailyTask = $task->dailyTasks()->create([
            'assigned_to' => $employee->id,
            'created_by' => $employee->id,
            'title' => 'Review support queue',
            'work_date' => today()->toDateString(),
            'planned_start_time' => '09:00',
            'planned_end_time' => '10:00',
            'original_work_date' => today()->toDateString(),
            'original_planned_start_time' => '09:00',
            'original_planned_end_time' => '10:00',
            'status' => 'pending',
        ]);

        $this->actingAs($employee)
            ->get(route('daily-tasks.index'))
            ->assertRedirect(route('tasks.index'));
        $this->getJson(route('daily-tasks.index'))
            ->assertOk()
            ->assertJsonPath('ok', true);
        $this->get(route('daily-tasks.create', $task))
            ->assertRedirect(route('tasks.show', $task).'?open_daily_task=1#panel-daily-tasks');
        $this->get(route('daily-tasks.show', [$task, $dailyTask]))
            ->assertRedirect(route('tasks.show', $task).'#daily-task-'.$dailyTask->id);
        $this->getJson(route('daily-tasks.show', [$task, $dailyTask]))
            ->assertOk()
            ->assertJsonPath('daily_task.id', $dailyTask->id);
    }

    public function test_employee_cannot_create_a_daily_task_for_another_employee(): void
    {
        $employee = $this->createEmployee();
        $otherEmployee = $this->createEmployee();
        $task = $this->createAssignedTask($employee);

        $this->actingAs($employee)
            ->from(route('tasks.show', $task))
            ->post(route('daily-tasks.store', $task), [
                'title' => 'Work assigned to another employee',
                'assigned_to' => $otherEmployee->id,
                'work_date' => today()->toDateString(),
                'planned_start_time' => '09:00',
                'planned_end_time' => '10:00',
                'priority' => 'medium',
            ])
            ->assertRedirect(route('tasks.show', $task))
            ->assertSessionHasErrors('assigned_to');

        $this->assertDatabaseCount('daily_tasks', 0);
    }

    public function test_daily_task_cannot_reference_a_milestone_from_another_task(): void
    {
        $employee = $this->createEmployee();
        $task = $this->createAssignedTask($employee);
        $otherTask = $this->createTask();
        $milestone = $otherTask->milestones()->create([
            'created_by' => $this->admin->id,
            'title' => 'Unrelated milestone',
            'target_date' => today()->addDays(2)->toDateString(),
        ]);

        $this->actingAs($this->admin)
            ->from(route('tasks.show', $task))
            ->post(route('daily-tasks.store', $task), [
                'title' => 'Link to foreign milestone',
                'assigned_to' => $employee->id,
                'milestone_id' => $milestone->id,
                'work_date' => today()->toDateString(),
                'planned_start_time' => '09:00',
                'planned_end_time' => '10:00',
                'priority' => 'medium',
            ])
            ->assertRedirect(route('tasks.show', $task))
            ->assertSessionHasErrors('milestone_id');

        $this->assertDatabaseCount('daily_tasks', 0);
    }

    public function test_daily_task_completion_records_activity_without_completing_its_milestone_or_main_task(): void
    {
        $employee = $this->createEmployee();
        $task = $this->createAssignedTask($employee);
        $milestone = $task->milestones()->create([
            'created_by' => $this->admin->id,
            'title' => 'Prepare final review',
            'target_date' => today()->addDays(2)->toDateString(),
        ]);
        $milestone->assignees()->attach($employee->id);

        $response = $this->actingAs($employee)->post(route('daily-tasks.store', $task), [
            'title' => 'Complete final review checklist',
            'milestone_id' => $milestone->id,
            'work_date' => today()->toDateString(),
            'planned_start_time' => '09:00',
            'planned_end_time' => '10:00',
            'priority' => 'medium',
        ]);
        $dailyTask = DailyTask::query()->where('task_id', $task->id)->firstOrFail();
        $response->assertRedirect(route('tasks.show', $task).'#panel-daily-tasks');

        $this->post(route('daily-tasks.action', [$task, $dailyTask, 'start']));
        $this->post(route('daily-tasks.action', [$task, $dailyTask, 'complete']), [
            'remarks' => 'Checklist complete.',
        ])->assertRedirect();

        $this->assertDatabaseHas('daily_tasks', [
            'id' => $dailyTask->id,
            'status' => 'completed',
            'progress_percentage' => 100,
        ]);
        $this->assertDatabaseHas('milestones', [
            'id' => $milestone->id,
            'status' => 'pending',
            'progress_percentage' => 0,
        ]);
        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'status' => 'in_progress',
        ]);
        $this->assertDatabaseHas('task_status_histories', [
            'daily_task_id' => $dailyTask->id,
            'event_type' => 'daily_task_completed',
        ]);
    }

    public function test_manager_can_create_and_update_a_milestone_with_assignees_and_audit_history(): void
    {
        $employee = $this->createEmployee();
        $manager = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
        ]);
        $manager->assignRole('Manager');
        $task = $this->createTask();

        $this->actingAs($manager)
            ->post(route('milestones.store', $task), [
                'title' => 'Confirm transfer records',
                'expected_outcome' => 'All records reconciled',
                'target_date' => today()->addDays(5)->toDateString(),
                'assignee_ids' => [$employee->id],
            ])
            ->assertRedirect();

        $milestone = Milestone::query()->where('task_id', $task->id)->firstOrFail();
        $this->assertTrue($milestone->assignees()->whereKey($employee->id)->exists());

        $this->actingAs($manager)
            ->patch(route('milestones.update', [$task, $milestone]), [
                'status' => 'completed',
                'remarks' => 'Outcome verified.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('milestones', [
            'id' => $milestone->id,
            'status' => 'completed',
            'progress_percentage' => 100,
            'completed_by' => $manager->id,
        ]);
        $this->assertDatabaseHas('task_status_histories', [
            'milestone_id' => $milestone->id,
            'event_type' => 'milestone_status',
            'old_status' => 'pending',
            'new_status' => 'completed',
        ]);
    }

    public function test_task_detail_places_subtasks_in_their_tab_and_excludes_them_from_the_timeline(): void
    {
        $task = $this->createTask();
        $subtask = $task->subtasks()->create([
            'created_by' => $this->admin->id,
            'title' => 'Review the account transfer',
        ]);
        $milestone = $task->milestones()->create([
            'created_by' => $this->admin->id,
            'title' => 'Final reconciliation milestone',
            'target_date' => today()->addDays(4)->toDateString(),
        ]);
        $dailyTask = $task->dailyTasks()->create([
            'assigned_to' => $this->admin->id,
            'created_by' => $this->admin->id,
            'title' => 'Review reconciliation',
            'work_date' => today()->toDateString(),
            'planned_start_time' => '09:00',
            'planned_end_time' => '10:00',
            'original_work_date' => today()->toDateString(),
            'original_planned_start_time' => '09:00',
            'original_planned_end_time' => '10:00',
            'status' => 'pending',
        ]);
        $task->statusHistory()->create([
            'milestone_id' => $milestone->id,
            'changed_by' => $this->admin->id,
            'event_type' => 'milestone_created',
            'new_status' => 'pending',
            'remarks' => 'Milestone-only activity marker.',
        ]);
        $dailyTask->activity()->create([
            'task_id' => $task->id,
            'changed_by' => $this->admin->id,
            'event_type' => 'daily_task_created',
            'new_status' => 'pending',
            'remarks' => 'Daily-task-only activity marker.',
        ]);
        $task->statusHistory()->create([
            'subtask_id' => $subtask->id,
            'changed_by' => $this->admin->id,
            'event_type' => 'subtask_status_changed',
            'new_status' => 'in_progress',
            'remarks' => 'Subtask-only activity marker.',
        ]);

        $response = $this->actingAs($this->admin)->get(route('tasks.show', $task));

        $response->assertOk()
            ->assertSee('id="panel-milestones"', false)
            ->assertSee('id="panel-daily-tasks"', false)
            ->assertSee('Final reconciliation milestone')
            ->assertSee('id="daily-updates-table"', false)
            ->assertSee('Date')
            ->assertSee('Employee Name')
            ->assertSee('Department')
            ->assertSee('Task')
            ->assertSee('Daily Task Status')
            ->assertSee($this->admin->name)
            ->assertSee($this->department->name)
            ->assertSee('Pending')
            ->assertSee('id="panel-subtasks"', false)
            ->assertSee('id="subtasks-table"', false)
            ->assertDontSee('Subtask-only activity marker.');

        $html = $response->getContent();
        $mainTimelineStart = strpos($html, 'id="panel-timeline"');
        $mainTimelineEnd = strpos($html, 'id="panel-milestones"');
        $this->assertNotFalse($mainTimelineStart);
        $this->assertNotFalse($mainTimelineEnd);
        $mainTimeline = substr($html, $mainTimelineStart, $mainTimelineEnd - $mainTimelineStart);
        $this->assertStringNotContainsString('Milestone-only activity marker.', $mainTimeline);
        $this->assertStringContainsString('Daily-task-only activity marker.', $mainTimeline);
        $this->assertStringContainsString('Daily Task: Review reconciliation', $mainTimeline);
        $this->assertStringContainsString('Status: Pending', $mainTimeline);
        $this->assertStringNotContainsString('Subtask-only activity marker.', $mainTimeline);
        $this->assertStringNotContainsString('data-tl-row="subtasks"', $mainTimeline);
    }

    public function test_ceo_and_team_leader_can_view_daily_updates_but_cannot_change_status_or_reassign(): void
    {
        $employee = $this->createEmployee();
        $task = $this->createAssignedTask($employee);
        $dailyTask = $task->dailyTasks()->create([
            'assigned_to' => $employee->id,
            'created_by' => $this->admin->id,
            'title' => 'Review transfer records',
            'work_date' => today()->toDateString(),
            'planned_start_time' => '09:00',
            'planned_end_time' => '10:00',
            'original_work_date' => today()->toDateString(),
            'original_planned_start_time' => '09:00',
            'original_planned_end_time' => '10:00',
            'status' => 'pending',
        ]);

        foreach (['CEO', 'Team Leader'] as $roleName) {
            $viewer = User::factory()->create([
                'company_id' => $this->company->id,
                'department_id' => $this->department->id,
            ]);
            $viewer->assignRole($roleName);

            $this->actingAs($viewer)
                ->get(route('tasks.show', $task))
                ->assertOk()
                ->assertSee($employee->name)
                ->assertSee('Daily Task Status')
                ->assertDontSee('Add Daily Task')
                ->assertDontSee(route('daily-tasks.action', [$task, $dailyTask, 'start']));

            $this->post(route('daily-tasks.action', [$task, $dailyTask, 'start']))
                ->assertForbidden();
            $this->post(route('daily-tasks.action', [$task, $dailyTask, 'reassign']), [
                'assigned_to' => $this->admin->id,
            ])->assertForbidden();
        }

        $this->assertDatabaseHas('daily_tasks', [
            'id' => $dailyTask->id,
            'status' => 'pending',
            'assigned_to' => $employee->id,
        ]);
    }

    public function test_admin_can_change_daily_task_status_from_the_task_detail_table(): void
    {
        $employee = $this->createEmployee();
        $task = $this->createAssignedTask($employee);
        $dailyTask = $task->dailyTasks()->create([
            'assigned_to' => $employee->id,
            'created_by' => $this->admin->id,
            'title' => 'Reconcile daily transfers',
            'work_date' => today()->toDateString(),
            'planned_start_time' => '09:00',
            'planned_end_time' => '10:00',
            'original_work_date' => today()->toDateString(),
            'original_planned_start_time' => '09:00',
            'original_planned_end_time' => '10:00',
            'status' => 'pending',
        ]);

        $this->actingAs($this->admin)
            ->get(route('tasks.show', $task))
            ->assertSee(route('daily-tasks.action', [$task, $dailyTask, 'start']), false)
            ->assertDontSee('Add Daily Task');

        $this->post(route('daily-tasks.action', [$task, $dailyTask, 'start']))
            ->assertRedirect();

        $this->assertDatabaseHas('daily_tasks', [
            'id' => $dailyTask->id,
            'status' => 'in_progress',
        ]);
    }

    public function test_manager_cannot_change_daily_task_status(): void
    {
        $employee = $this->createEmployee();
        $manager = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
        ]);
        $manager->assignRole('Manager');
        $task = $this->createAssignedTask($employee);
        $dailyTask = $task->dailyTasks()->create([
            'assigned_to' => $employee->id,
            'created_by' => $this->admin->id,
            'title' => 'Review daily queue',
            'work_date' => today()->toDateString(),
            'planned_start_time' => '09:00',
            'planned_end_time' => '10:00',
            'original_work_date' => today()->toDateString(),
            'original_planned_start_time' => '09:00',
            'original_planned_end_time' => '10:00',
            'status' => 'pending',
        ]);

        $this->actingAs($manager)
            ->get(route('tasks.show', $task))
            ->assertOk()
            ->assertDontSee(route('daily-tasks.action', [$task, $dailyTask, 'start']), false);

        $this->post(route('daily-tasks.action', [$task, $dailyTask, 'start']))
            ->assertForbidden();

        $this->assertDatabaseHas('daily_tasks', [
            'id' => $dailyTask->id,
            'status' => 'pending',
        ]);
    }

    private function createEmployee(): User
    {
        $employee = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
        ]);
        $employee->assignRole('Employee');

        return $employee;
    }

    private function createAssignedTask(User $employee): Task
    {
        $task = $this->createTask([
            'status' => 'in_progress',
            'assigned_to' => $employee->id,
        ]);
        TaskAssignment::query()->create([
            'task_id' => $task->id,
            'user_id' => $employee->id,
            'assigned_by' => $this->admin->id,
            'reason' => 'Initial assignment',
            'assigned_at' => now(),
            'is_active' => true,
        ]);

        return $task;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createTask(array $attributes = []): Task
    {
        return Task::factory()->create(array_merge([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'created_by' => $this->admin->id,
            'status' => 'assigned',
            'due_date' => today()->addDays(10)->toDateString(),
            'original_deadline' => today()->addDays(10)->toDateString(),
        ], $attributes));
    }
}
