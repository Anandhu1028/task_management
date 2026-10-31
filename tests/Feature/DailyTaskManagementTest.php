<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\DailyTask;
use App\Models\Department;
use App\Models\Milestone;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\User;
use App\Services\DailyTaskWorkflowService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
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
        $heldTaskResponse = $this->get(route('tasks.show', $task))
            ->assertSee('Unhold')
            ->assertDontSee('Status: On Hold', false);
        $this->assertSame(1, preg_match("/data-timeline-model='([^']+)'/", $heldTaskResponse->getContent(), $heldTimelineMatch));
        $heldTimelineModel = json_decode(
            html_entity_decode($heldTimelineMatch[1], ENT_QUOTES | ENT_HTML5),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $this->assertSame(
            'On Hold',
            collect($heldTimelineModel['dailyUpdates'])->firstWhere('dailyTaskId', $dailyTask->id)['currentStatus'],
        );
        $this->post(route('daily-tasks.action', [$task, $dailyTask, 'resume']))
            ->assertRedirect($returnUrl);

        $this->assertDatabaseHas('daily_tasks', [
            'id' => $dailyTask->id,
            'status' => 'in_progress',
        ]);

        $this->get(route('tasks.show', $task))
            ->assertSee('id="daily-task-update-form-'.$dailyTask->id.'"', false);
        $this->from(route('tasks.show', $task))
            ->post(route('daily-tasks.action', [$task, $dailyTask, 'update']), [
                'blockers' => 'Still waiting on credentials.',
            ])
            ->assertSessionHasErrors('remarks');
        $this->assertDatabaseMissing('task_status_histories', [
            'daily_task_id' => $dailyTask->id,
            'event_type' => 'daily_task_update_submitted',
        ]);

        $this->post(route('daily-tasks.action', [$task, $dailyTask, 'update']), [
            'remarks' => 'API contract verified.',
            'blockers' => 'Credentials are now available.',
        ])->assertRedirect($returnUrl);

        $this->assertDatabaseHas('task_status_histories', [
            'daily_task_id' => $dailyTask->id,
            'event_type' => 'daily_task_update_submitted',
            'remarks' => 'API contract verified.',
        ]);
        $this->assertDatabaseHas('daily_tasks', [
            'id' => $dailyTask->id,
            'status' => 'in_progress',
            'blockers' => 'Credentials are now available.',
        ]);

        $updatedTaskResponse = $this->get(route('tasks.show', $task))
            ->assertSee('Reconcile support queue')
            ->assertSee('On Hold')
            ->assertSee('Daily Task: ', false)
            ->assertSee('Current status', false)
            ->assertDontSee('Status: On Hold', false)
            ->assertSee('daily_task_update_submitted')
            ->assertSee('Update: API contract verified.');
        $this->assertSame(1, preg_match("/data-timeline-model='([^']+)'/", $updatedTaskResponse->getContent(), $updatedTimelineMatch));
        $updatedTimelineModel = json_decode(
            html_entity_decode($updatedTimelineMatch[1], ENT_QUOTES | ENT_HTML5),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $this->assertSame(
            'In Progress',
            collect($updatedTimelineModel['dailyUpdates'])->firstWhere('dailyTaskId', $dailyTask->id)['currentStatus'],
        );
    }

    public function test_employee_assigned_through_the_legacy_task_assignee_field_sees_add_daily_task_button(): void
    {
        $employee = $this->createEmployee();
        $task = $this->createTask([
            'status' => 'in_progress',
            'assigned_to' => $employee->id,
        ]);

        $this->actingAs($employee)
            ->get(route('tasks.show', $task))
            ->assertOk()
            ->assertSee('id="daily-task-create-toggle"', false)
            ->assertSee('Add Daily Task');
    }

    public function test_employee_can_add_daily_task_when_role_permission_has_not_been_seeded(): void
    {
        $employee = $this->createEmployee();
        $task = $this->createAssignedTask($employee);
        Role::findByName('Employee')->revokePermissionTo('daily_tasks.create_own');

        $this->actingAs($employee)
            ->get(route('tasks.show', $task))
            ->assertOk()
            ->assertSee('id="daily-task-create-toggle"', false);

        $this->post(route('daily-tasks.store', $task), [
            'title' => 'Review unseeded-permission task',
            'work_date' => today()->toDateString(),
            'quick_entry' => '1',
            'priority' => 'medium',
        ])->assertRedirect(route('tasks.show', $task).'#panel-daily-tasks');

        $this->assertDatabaseHas('daily_tasks', [
            'task_id' => $task->id,
            'assigned_to' => $employee->id,
            'title' => 'Review unseeded-permission task',
        ]);
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
        $this->assertStringContainsString('Review reconciliation', $mainTimeline);
        $this->assertStringContainsString('Daily Task: ', $mainTimeline);
        $this->assertStringNotContainsString('Status: Pending', $mainTimeline);
        $this->assertStringContainsString('.tl-event-tooltip__daily-info-row', $mainTimeline);
        $this->assertStringNotContainsString('Subtask-only activity marker.', $mainTimeline);
        $this->assertStringNotContainsString('data-tl-row="subtasks"', $mainTimeline);
    }

    public function test_timeline_shows_daily_task_history_chronologically_with_employee_and_department(): void
    {
        $employee = $this->createEmployee();
        $task = $this->createAssignedTask($employee);
        $workDate = today()->toDateString();
        $dailyTask = $task->dailyTasks()->create([
            'assigned_to' => $employee->id,
            'created_by' => $employee->id,
            'title' => 'Build the user profile endpoint',
            'description' => 'Implement and verify the endpoint.',
            'work_date' => $workDate,
            'planned_start_time' => '09:00',
            'planned_end_time' => '17:00',
            'original_work_date' => $workDate,
            'original_planned_start_time' => '09:00',
            'original_planned_end_time' => '17:00',
            'status' => 'completed',
        ]);
        $secondEmployee = $this->createEmployee();
        $secondDepartment = Department::factory()->create(['company_id' => $this->company->id]);
        $secondEmployee->update(['department_id' => $secondDepartment->id]);
        $eventTimes = [
            'daily_task_started' => ['09:30', 'pending', 'in_progress', null],
            'daily_task_update_submitted' => ['11:00', 'in_progress', 'in_progress', 'Profile endpoint is implemented.'],
            'daily_task_progress_updated' => ['12:00', '20', '55', 'Progress recorded.'],
            'daily_task_on_hold' => ['14:00', 'in_progress', 'on_hold', 'Waiting for API credentials.'],
            'daily_task_resumed' => ['15:00', 'on_hold', 'in_progress', null],
            'daily_task_completed' => ['16:30', 'in_progress', 'completed', 'Endpoint verified.'],
        ];

        foreach ($eventTimes as $eventType => [$time, $oldStatus, $newStatus, $remarks]) {
            $history = $dailyTask->activity()->create([
                'task_id' => $task->id,
                'changed_by' => $employee->id,
                'event_type' => $eventType,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'remarks' => $remarks,
            ]);
            $history->forceFill([
                'created_at' => Carbon::parse($workDate.' '.$time),
                'updated_at' => Carbon::parse($workDate.' '.$time),
            ])->save();
        }

        $secondDailyTask = $task->dailyTasks()->create([
            'assigned_to' => $secondEmployee->id,
            'created_by' => $secondEmployee->id,
            'title' => 'Document the API response',
            'work_date' => $workDate,
            'planned_start_time' => '13:00',
            'planned_end_time' => '14:00',
            'original_work_date' => $workDate,
            'original_planned_start_time' => '13:00',
            'original_planned_end_time' => '14:00',
            'status' => 'pending',
        ]);
        $secondTaskHistory = $secondDailyTask->activity()->create([
            'task_id' => $task->id,
            'changed_by' => $secondEmployee->id,
            'event_type' => 'daily_task_created',
            'new_status' => 'pending',
        ]);
        $secondTaskHistory->forceFill([
            'created_at' => Carbon::parse($workDate.' 08:45'),
            'updated_at' => Carbon::parse($workDate.' 08:45'),
        ])->save();

        $response = $this->actingAs($this->admin)->get(route('tasks.show', $task))->assertOk();
        $response->assertSee($secondDepartment->name);
        $html = $response->getContent();
        $timelineStart = strpos($html, 'id="panel-timeline"');
        $timelineEnd = strpos($html, 'id="panel-milestones"');
        $this->assertNotFalse($timelineStart);
        $this->assertNotFalse($timelineEnd);
        $timeline = substr($html, $timelineStart, $timelineEnd - $timelineStart);

        $this->assertStringContainsString('const hasDailyTaskActivity = events.some(event => event.isDailyTask === true);', $html);
        $this->assertStringContainsString('&& !hasDailyTaskActivity', $html);
        $this->assertStringContainsString('top: 50%;', $html);
        $this->assertStringContainsString('const rowHeight = eventCount > 0 ? (waiting ? 82 : 62) : 56;', $html);

        foreach ([
            'daily_task_started',
            'daily_task_update_submitted',
            'daily_task_progress_updated',
            'daily_task_on_hold',
            'daily_task_resumed',
            'daily_task_completed',
            'Update: Profile endpoint is implemented.',
        ] as $expectedText) {
            $this->assertStringContainsString($expectedText, $timeline);
        }

        $this->assertSame(1, preg_match("/data-timeline-model='([^']+)'/", $timeline, $timelineModelMatch));
        $timelineModel = json_decode(
            html_entity_decode($timelineModelMatch[1], ENT_QUOTES | ENT_HTML5),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $dailyTimelineActivities = collect($timelineModel['dailyUpdates']);
        $mainTimelineActivities = collect($timelineModel['activities']);
        $this->assertEqualsCanonicalizing(
            ['Build the user profile endpoint', 'Document the API response'],
            $dailyTimelineActivities->pluck('dailyTaskTitle')->unique()->values()->all(),
        );
        $dailyTaskActivity = $dailyTimelineActivities->firstWhere('dailyTaskId', $dailyTask->id);
        $this->assertSame('Completed', $dailyTaskActivity['currentStatus']);
        $this->assertSame((int) $dailyTask->progress_percentage, $dailyTaskActivity['progress']);
        $this->assertSame($workDate, $dailyTaskActivity['workDate']);
        $this->assertStringNotContainsString('Status: Completed', $dailyTaskActivity['detail']);
        $this->assertFalse($mainTimelineActivities->contains(
            fn (array $activity): bool => str_starts_with((string) $activity['type'], 'daily_task_')
                || str_starts_with((string) $activity['type'], 'daily_accountability_'),
        ));
        $this->assertTrue($dailyTimelineActivities->contains(
            fn (array $activity): bool => str_contains((string) $activity['detail'], 'Progress: 20% → 55%'),
        ));
        $chronologicalDailyEventTypes = $dailyTimelineActivities
            ->whereIn('type', array_keys($eventTimes))
            ->sortBy('timestamp')
            ->pluck('type')
            ->values()
            ->all();
        $this->assertSame(array_keys($eventTimes), $chronologicalDailyEventTypes);
        $this->assertTrue($dailyTimelineActivities->contains(
            fn (array $activity): bool => $activity['employee'] === $employee->name
                && $activity['department'] === $this->department->name,
        ));
        $this->assertTrue($dailyTimelineActivities->contains(
            fn (array $activity): bool => $activity['employee'] === $secondEmployee->name
                && $activity['department'] === $secondDepartment->name,
        ));
    }

    public function test_becoming_pending_is_recorded_as_a_daily_task_status_transition(): void
    {
        $employee = $this->createEmployee();
        $task = $this->createAssignedTask($employee);
        $dailyTask = $task->dailyTasks()->create([
            'assigned_to' => $employee->id,
            'created_by' => $employee->id,
            'title' => 'Start scheduled work',
            'work_date' => today()->toDateString(),
            'planned_start_time' => '09:00',
            'planned_end_time' => '10:00',
            'original_work_date' => today()->toDateString(),
            'original_planned_start_time' => '09:00',
            'original_planned_end_time' => '10:00',
            'status' => 'planned',
        ]);

        $updates = app(DailyTaskWorkflowService::class)->refreshDueStatuses(
            Carbon::parse(today()->toDateString().' 08:00'),
        );

        $this->assertSame(1, $updates);
        $this->assertDatabaseHas('daily_tasks', [
            'id' => $dailyTask->id,
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('task_status_histories', [
            'daily_task_id' => $dailyTask->id,
            'event_type' => 'daily_task_pending',
            'old_status' => 'planned',
            'new_status' => 'pending',
        ]);
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
