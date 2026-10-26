<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskAttachment;
use App\Models\TaskStatusHistory;
use App\Models\User;
use App\Notifications\TaskLifecycleNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TaskManagementTest extends TestCase
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
        $this->actingAs($this->admin);
        $this->company = Company::query()->where('status', 'active')->firstOrFail();
        $this->department = Department::query()->where('company_id', $this->company->id)->where('status', 'active')->firstOrFail();
    }

    public function test_authorized_user_can_view_all_tasks_page(): void
    {
        $response = $this->actingAs($this->admin)->get(route('tasks.index'));

        $response->assertOk()
            ->assertSee('All Tasks')
            ->assertSee('Total Tasks')
            ->assertSee('Create Task')
            ->assertSee('Export Report')
            ->assertDontSee('tf-user-filter-dropdown', false)
            ->assertSee('Filter by company')
            ->assertSee('Filter by department')
            ->assertSee('Filter by priority')
            ->assertSee('data-task-select-all', false)
            ->assertDontSee('aria-label="Filter tasks by status"', false)
            ->assertSee('createTaskPocSearch')
            ->assertDontSee('createTaskInitialStatus')
            ->assertSee('Assigned Employee')
            ->assertSee('name="attachments[]"', false)
            ->assertSee('multiple', false)
            ->assertSee('data-tf-notification-poll="'.route('notifications.poll').'"', false)
            ->assertSee('The original deadline is preserved in the task history.');
        $this->assertSame(1, substr_count($response->getContent(), 'id="createTaskModal"'));
        $response->assertSee("input.name = 'poc_ids[]';", false)
            ->assertDontSee("checkbox.name = 'poc_ids[]';", false);
    }

    public function test_notification_poll_returns_only_the_signed_in_users_latest_notifications(): void
    {
        $task = $this->createWorkflowTask();
        $otherUser = User::factory()->create();
        $this->admin->notify(new TaskLifecycleNotification(
            $task,
            'Team Leader',
            'Task status updated',
            'Team Leader changed the task status.',
        ));
        $otherUser->notify(new TaskLifecycleNotification(
            $task,
            'Team Leader',
            'Task status updated',
            'Team Leader changed the task status.',
        ));
        $notificationId = $this->admin->notifications()->value('id');

        $response = $this->actingAs($this->admin)
            ->getJson(route('notifications.poll'));

        $response->assertOk()
            ->assertJsonPath('unread_count', 1)
            ->assertJsonCount(1, 'notifications')
            ->assertJsonPath('notifications.0.id', $notificationId)
            ->assertJsonPath('notifications.0.title', 'Task status updated')
            ->assertJsonPath('notifications.0.task_title', $task->title)
            ->assertJsonPath('notifications.0.url', route('notifications.open', $notificationId))
            ->assertJsonPath('notifications.0.is_unread', true);
    }

    public function test_notification_poll_requires_authentication(): void
    {
        auth()->logout();

        $this->getJson(route('notifications.poll'))
            ->assertUnauthorized();
    }

    public function test_task_toolbar_filters_by_company_department_and_priority(): void
    {
        $matchingTask = Task::create([
            'task_code' => Task::generateCode(),
            'title' => 'Matching toolbar filter task',
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'task_type' => 'process',
            'priority' => 'high',
            'status' => 'assigned',
            'original_deadline' => now()->addDays(2)->toDateString(),
            'due_date' => now()->addDays(2)->toDateString(),
            'estimated_hours' => 2,
            'expected_output' => 'Deliverable',
            'created_by' => $this->admin->id,
        ]);
        Task::create([
            'task_code' => Task::generateCode(),
            'title' => 'Non-matching toolbar filter task',
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'task_type' => 'process',
            'priority' => 'low',
            'status' => 'unassigned',
            'original_deadline' => now()->addDays(2)->toDateString(),
            'due_date' => now()->addDays(2)->toDateString(),
            'estimated_hours' => 2,
            'expected_output' => 'Deliverable',
            'created_by' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->get(route('tasks.index', [
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'priority' => 'high',
        ]));

        $response->assertOk()
            ->assertSee('value="'.$this->company->id.'" selected', false)
            ->assertSee('value="'.$this->department->id.'" selected', false)
            ->assertSee('value="high" selected', false)
            ->assertSee($matchingTask->title)
            ->assertSee('tf-task-priority tf-task-priority--high', false)
            ->assertSee('aria-label="Priority: High"', false)
            ->assertDontSee('Non-matching toolbar filter task');
    }

    public function test_task_can_be_created_without_a_deadline(): void
    {
        $poc = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->admin)->post(route('tasks.store'), [
            'title' => 'Task with no deadline',
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'poc_ids' => [$poc->id],
            'task_type' => 'process',
            'priority' => 'medium',
            'estimated_hours' => 2,
            'expected_output' => 'A task that can be planned later.',
        ]);

        $response->assertRedirect(route('tasks.index'))
            ->assertSessionHas('success', 'Task created successfully.');
        $this->assertDatabaseHas('tasks', [
            'title' => 'Task with no deadline',
            'due_date' => null,
            'original_deadline' => null,
        ]);

        $this->get(route('tasks.index'))
            ->assertSee('No deadline');
    }

    public function test_task_update_returns_json_for_kanban_edits(): void
    {
        $task = $this->createWorkflowTask([
            'title' => 'Task edited from the Kanban board',
            'status' => 'assigned',
        ]);
        $newDeadline = today()->addDays(2)->toDateString();

        $response = $this->actingAs($this->admin)->putJson(route('tasks.update', $task), [
            'title' => $task->title,
            'company_id' => $task->company_id,
            'department_id' => $task->department_id,
            'task_type' => $task->task_type,
            'priority' => 'critical',
            'due_date' => $newDeadline,
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Task updated successfully.');
        $updatedTask = $task->fresh();
        $this->assertSame('critical', $updatedTask->priority);
        $this->assertSame($newDeadline, $updatedTask->due_date?->toDateString());
    }

    public function test_kanban_default_sort_uses_effective_deadline_then_priority_and_stable_order(): void
    {
        $oldestOverdue = $this->createWorkflowTask([
            'title' => 'Oldest overdue Kanban task',
            'status' => 'assigned',
            'due_date' => null,
            'original_deadline' => today()->subDays(4)->toDateString(),
        ]);
        $newerOverdue = $this->createWorkflowTask([
            'title' => 'Newer overdue Kanban task',
            'status' => 'assigned',
            'priority' => 'critical',
            'due_date' => null,
            'original_deadline' => today()->subDay()->toDateString(),
        ]);
        $todayCritical = $this->createWorkflowTask([
            'title' => 'Today critical Kanban task',
            'status' => 'assigned',
            'priority' => 'critical',
            'due_date' => today()->toDateString(),
            'original_deadline' => today()->toDateString(),
        ]);
        $todayHigh = $this->createWorkflowTask([
            'title' => 'Today high Kanban task',
            'status' => 'assigned',
            'priority' => 'high',
            'due_date' => today()->toDateString(),
            'original_deadline' => today()->toDateString(),
        ]);
        $todayMedium = $this->createWorkflowTask([
            'title' => 'Today medium Kanban task',
            'status' => 'assigned',
            'priority' => 'medium',
            'due_date' => today()->toDateString(),
            'original_deadline' => today()->toDateString(),
        ]);
        $todayMediumSameCreationTime = $this->createWorkflowTask([
            'title' => 'Today medium Kanban task with matching creation time',
            'status' => 'assigned',
            'priority' => 'medium',
            'due_date' => today()->toDateString(),
            'original_deadline' => today()->toDateString(),
        ]);
        $todayMediumOlderCreationTime = $this->createWorkflowTask([
            'title' => 'Today medium Kanban task with older creation time',
            'status' => 'assigned',
            'priority' => 'medium',
            'due_date' => today()->toDateString(),
            'original_deadline' => today()->toDateString(),
        ]);
        $stableCreationTime = now()->subHours(2);
        DB::table('tasks')->whereIn('id', [$todayMedium->id, $todayMediumSameCreationTime->id])
            ->update(['created_at' => $stableCreationTime]);
        DB::table('tasks')->where('id', $todayMediumOlderCreationTime->id)
            ->update(['created_at' => now()->subHours(3)]);
        $todayLow = $this->createWorkflowTask([
            'title' => 'Today low Kanban task',
            'status' => 'assigned',
            'priority' => 'low',
            'due_date' => today()->toDateString(),
            'original_deadline' => today()->toDateString(),
        ]);
        $tomorrow = $this->createWorkflowTask([
            'title' => 'Tomorrow Kanban task',
            'status' => 'assigned',
            'priority' => 'high',
            'due_date' => today()->addDay()->toDateString(),
            'original_deadline' => today()->addDay()->toDateString(),
        ]);
        $revisedDeadline = $this->createWorkflowTask([
            'title' => 'Revised Kanban deadline task',
            'status' => 'assigned',
            'priority' => 'critical',
            'due_date' => today()->addDays(5)->toDateString(),
            'original_deadline' => today()->subDays(30)->toDateString(),
        ]);
        $noDeadline = $this->createWorkflowTask([
            'title' => 'No deadline Kanban task',
            'status' => 'assigned',
            'priority' => 'critical',
            'due_date' => null,
            'original_deadline' => null,
        ]);

        $response = $this->actingAs($this->admin)->get(route('tasks.index'));

        $response->assertOk()
            ->assertSee('id="taskKanbanSort"', false)
            ->assertSee('value="deadline_priority" selected', false)
            ->assertSee('Overdue')
            ->assertSee('Today')
            ->assertSee('No deadline');

        $this->assertKanbanOrder(
            $response,
            $oldestOverdue,
            $newerOverdue,
            $todayCritical,
            $todayHigh,
            $todayMediumOlderCreationTime,
            $todayMedium,
            $todayMediumSameCreationTime,
            $todayLow,
            $tomorrow,
            $revisedDeadline,
            $noDeadline,
        );
    }

    public function test_kanban_sort_modes_respect_filters_and_keep_tasks_in_their_workflow_columns(): void
    {
        $olderCreated = $this->createWorkflowTask([
            'title' => 'Older created Kanban task',
            'status' => 'assigned',
            'priority' => 'medium',
            'due_date' => today()->addDays(10)->toDateString(),
            'original_deadline' => today()->addDays(10)->toDateString(),
        ]);
        $newerCritical = $this->createWorkflowTask([
            'title' => 'Newer critical Kanban task',
            'status' => 'assigned',
            'priority' => 'critical',
            'due_date' => today()->addDay()->toDateString(),
            'original_deadline' => today()->addDay()->toDateString(),
        ]);
        $overdueHigh = $this->createWorkflowTask([
            'title' => 'Overdue high Kanban task',
            'status' => 'assigned',
            'priority' => 'high',
            'due_date' => today()->subDays(4)->toDateString(),
            'original_deadline' => today()->subDays(4)->toDateString(),
        ]);
        $otherStatus = $this->createWorkflowTask([
            'title' => 'On hold Kanban task',
            'status' => 'on_hold',
            'priority' => 'critical',
            'due_date' => today()->subDays(8)->toDateString(),
            'original_deadline' => today()->subDays(8)->toDateString(),
        ]);

        DB::table('tasks')
            ->where('id', $olderCreated->id)
            ->update([
                'title' => 'Recently updated Kanban task',
                'updated_at' => now()->addMinute(),
            ]);
        $filterParameters = [
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'status' => 'assigned',
        ];

        $response = $this->actingAs($this->admin)->get(route('tasks.index', [
            ...$filterParameters,
            'sort' => 'priority_deadline',
        ]));

        $response->assertOk()
            ->assertSee('value="priority_deadline" selected', false);
        $this->assertKanbanOrder($response, $newerCritical, $overdueHigh, $olderCreated);
        $this->assertStringNotContainsString('data-task-id="'.$otherStatus->id.'"', $this->kanbanHtml($response));
        $this->assertSame('on_hold', $otherStatus->fresh()->status);

        $recentlyCreated = $this->actingAs($this->admin)->get(route('tasks.index', [
            ...$filterParameters,
            'sort' => 'recently_created',
        ]));
        $this->assertKanbanOrder($recentlyCreated, $overdueHigh, $newerCritical, $olderCreated);

        $recentlyUpdated = $this->actingAs($this->admin)->get(route('tasks.index', [
            ...$filterParameters,
            'sort' => 'recently_updated',
        ]));
        $this->assertKanbanOrder($recentlyUpdated, $olderCreated, $overdueHigh, $newerCritical);

        $nearestDeadline = $this->actingAs($this->admin)->get(route('tasks.index', [
            ...$filterParameters,
            'sort' => 'nearest_deadline',
        ]));
        $this->assertKanbanOrder($nearestDeadline, $newerCritical, $olderCreated, $overdueHigh);

        $oldestDeadline = $this->actingAs($this->admin)->get(route('tasks.index', [
            ...$filterParameters,
            'sort' => 'oldest_deadline',
        ]));
        $this->assertKanbanOrder($oldestDeadline, $overdueHigh, $newerCritical, $olderCreated);
    }

    public function test_editing_a_task_uses_the_prefilled_shared_task_modal(): void
    {
        $poc = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'status' => 'active',
        ]);
        $task = Task::create([
            'task_code' => Task::generateCode(),
            'title' => 'Existing task for edit',
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'task_type' => 'project',
            'priority' => 'high',
            'status' => 'in_progress',
            'progress_percentage' => 45,
            'original_deadline' => now()->addDays(4)->toDateString(),
            'due_date' => now()->addDays(6)->toDateString(),
            'estimated_hours' => 12,
            'expected_output' => 'Existing expected output',
            'description' => 'Existing task description',
            'created_by' => $this->admin->id,
        ]);
        $task->pocs()->attach($poc->id);

        $response = $this->actingAs($this->admin)->get(route('tasks.index', ['edit' => $task->id]));

        $response->assertOk()
            ->assertSee('data-form-mode="edit"', false)
            ->assertSee('Edit Task')
            ->assertSee('value="Existing task for edit"', false)
            ->assertSee('value="high" selected', false)
            ->assertSee('value="project" selected', false)
            ->assertSee('id="createTaskDueDate"', false)
            ->assertSee('name="due_date"', false)
            ->assertDontSee('id="createTaskStatus"', false)
            ->assertSee('Existing expected output')
            ->assertSee('action="'.route('tasks.update', $task).'"', false)
            ->assertSee('new Set(["'.$poc->id.'"])', false)
            ->assertSee('method="POST"', false)
            ->assertSee('name="_method" value="PUT"', false)
            ->assertSee('data-task-edit', false)
            ->assertSee('data-task-id="'.$task->id.'"', false)
            ->assertSee('data-create-action="'.route('tasks.store').'"', false)
            ->assertSee('data-update-url="'.route('tasks.update', ['task' => '__TASK_ID__']).'"', false)
            ->assertSee('window.bootstrap.Modal.getOrCreateInstance(modal).show();', false)
            ->assertDontSee('id="editTaskModal"', false);

        $this->assertSame(1, substr_count($response->getContent(), 'id="createTaskModal"'));
    }

    public function test_user_can_create_a_task_with_multiple_pocs_and_enhancements(): void
    {
        $roleIds = Role::query()
            ->whereIn('name', ['CEO', 'Team Leader', 'Employee'])
            ->pluck('id', 'name');
        $poc1 = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'status' => 'active',
            'role_id' => $roleIds['CEO'],
        ]);
        $poc2 = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'status' => 'active',
            'role_id' => $roleIds['Team Leader'],
        ]);
        $poc2->assignRole('Team Leader');
        $poc3 = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'status' => 'active',
            'role_id' => $roleIds['Employee'],
        ]);
        $assignee = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'status' => 'active',
        ]);
        $ceo = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'status' => 'active',
            'role_id' => $roleIds['CEO'],
        ]);
        $secondAssignee = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'status' => 'active',
        ]);

        $deadline = now()->addDays(5)->toDateString();

        $response = $this->actingAs($this->admin)->followingRedirects()->post(route('tasks.store'), [
            'title' => 'Deliver System Enhancements',
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'assigned_to' => [$assignee->id, $secondAssignee->id],
            'poc_ids' => [$poc1->id, $poc2->id, $poc3->id],
            'task_type' => 'project',
            'priority' => 'critical',
            'original_deadline' => $deadline,
            'estimated_hours' => 18.5,
            'expected_output' => 'Fully working and tested feature code.',
            'description' => 'Implementation of task enhancements.',
        ]);

        $response->assertOk()
            ->assertSee('Task created successfully.');

        $this->assertDatabaseHas('tasks', [
            'title' => 'Deliver System Enhancements',
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'assigned_to' => $assignee->id,
            'task_type' => 'project',
            'priority' => 'critical',
            'estimated_hours' => 18.5,
            'expected_output' => 'Fully working and tested feature code.',
        ]);

        $task = Task::query()->where('title', 'Deliver System Enhancements')->firstOrFail();
        $this->assertSame('assigned', $task->status);
        $this->assertSame($deadline, $task->original_deadline->toDateString());
        $taskListResponse = $this->actingAs($this->admin)->get(route('tasks.index', [
            'search' => 'Deliver System Enhancements',
        ]));
        $taskListResponse->assertOk()
            ->assertSee($this->company->name)
            ->assertSeeInOrder(['Deliver System Enhancements', $task->task_code], false);
        $this->assertCount(3, $task->pocs);
        $this->assertTrue($task->pocs->contains($poc1));
        $this->assertTrue($task->pocs->contains($poc2));
        $this->assertTrue($task->pocs->contains($poc3));
        $this->assertDatabaseHas('task_pocs', ['task_id' => $task->id, 'user_id' => $poc1->id]);
        $this->assertDatabaseHas('task_pocs', ['task_id' => $task->id, 'user_id' => $poc2->id]);
        $this->assertDatabaseHas('task_pocs', ['task_id' => $task->id, 'user_id' => $poc3->id]);
        $this->assertCount(2, $task->activeAssignees);
        $this->assertDatabaseHas('task_assignments', [
            'task_id' => $task->id,
            'user_id' => $assignee->id,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('task_assignments', [
            'task_id' => $task->id,
            'user_id' => $secondAssignee->id,
            'is_active' => true,
        ]);

        foreach ([$this->admin, $ceo, $poc1, $poc2, $poc3, $assignee, $secondAssignee] as $recipient) {
            $this->assertDatabaseHas('notifications', [
                'notifiable_id' => $recipient->id,
                'data->task_id' => $task->id,
            ]);
        }

        $pocNotification = $poc2->notifications()
            ->where('data->task_id', $task->id)
            ->firstOrFail();
        $notificationData = $pocNotification->data;
        $this->assertSame($this->admin->name, $notificationData['actor_name']);
        $this->assertSame($task->title, $notificationData['task_title']);
        $this->assertStringContainsString($poc2->name, $notificationData['body']);
        $this->assertStringContainsString($this->admin->name, $notificationData['body']);
        $this->assertStringContainsString('routed through', $notificationData['body']);
        $this->assertStringContainsString($assignee->name, $notificationData['body']);

        $this->actingAs($poc2)
            ->get(route('tasks.index'))
            ->assertSee('New task created')
            ->assertSee($this->admin->name)
            ->assertSee($task->title);

        $this->get(route('notifications.open', $pocNotification))
            ->assertRedirect(route('tasks.show', $task));
        $this->assertNotNull($pocNotification->fresh()->read_at);
    }

    public function test_user_can_create_a_task_with_multiple_attachments_and_download_them(): void
    {
        Storage::fake('local');

        $poc = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->admin)->post(route('tasks.store'), [
            'title' => 'Task with supporting files',
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'poc_ids' => [$poc->id],
            'task_type' => 'project',
            'priority' => 'medium',
            'original_deadline' => now()->addDays(3)->toDateString(),
            'estimated_hours' => 2,
            'expected_output' => 'Deliver the attached materials.',
            'attachments' => [
                UploadedFile::fake()->image('wireframe.png'),
                UploadedFile::fake()->createWithContent('notes.txt', 'Review the attached wireframe.'),
            ],
        ]);

        $response->assertRedirect(route('tasks.index'))
            ->assertSessionHas('success', 'Task created successfully.');
        $this->get(route('tasks.index'))
            ->assertSee('id="tfToast"', false)
            ->assertSee('Task created successfully.');
        $this->assertDatabaseCount('task_attachments', 2);

        $task = Task::query()->where('title', 'Task with supporting files')->firstOrFail();
        $attachments = TaskAttachment::query()->where('task_id', $task->id)->get();
        foreach ($attachments as $attachment) {
            Storage::disk('local')->assertExists($attachment->path);
        }

        $detailResponse = $this->get(route('tasks.show', $task));
        $detailResponse->assertOk()
            ->assertSee('Task files')
            ->assertSee('wireframe.png')
            ->assertSee('notes.txt')
            ->assertSee(route('tasks.attachments.download', [$task, $attachments->first()]), false)
            ->assertSee(route('tasks.attachments.preview', [$task, $attachments->first()]), false);

        $downloadResponse = $this->get(route('tasks.attachments.download', [$task, $attachments->first()]));
        $downloadResponse->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename='.$attachments->first()->original_name);

        $previewResponse = $this->get(route('tasks.attachments.preview', [$task, $attachments->first()]));
        $previewResponse->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Content-Disposition', 'inline; filename='.$attachments->first()->original_name)
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_task_detail_updates_notify_admins_and_current_task_participants(): void
    {
        $roleIds = Role::query()
            ->whereIn('name', ['CEO', 'Team Leader', 'Employee'])
            ->pluck('id', 'name');
        $poc = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'status' => 'active',
            'role_id' => $roleIds['Team Leader'],
        ]);
        $poc->assignRole('Team Leader');
        $employee = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'status' => 'active',
            'role_id' => $roleIds['Employee'],
        ]);
        $employee->assignRole('Employee');
        $ceo = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'status' => 'active',
            'role_id' => $roleIds['CEO'],
        ]);
        $task = $this->createWorkflowTask([
            'status' => 'assigned',
            'assigned_to' => $employee->id,
        ]);
        $task->pocs()->attach($poc->id);
        TaskAssignment::create([
            'task_id' => $task->id,
            'user_id' => $employee->id,
            'assigned_by' => $this->admin->id,
            'reason' => 'Initial assignment',
            'assigned_at' => now(),
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->put(route('tasks.update', $task), [
                'title' => 'Updated notification task',
                'company_id' => $this->company->id,
                'department_id' => $this->department->id,
                'task_type' => 'process',
                'priority' => 'high',
                'expected_output' => 'Updated task deliverable.',
            ])
            ->assertRedirect(route('tasks.index'));

        foreach ([$ceo, $poc, $employee] as $recipient) {
            $this->assertDatabaseHas('notifications', [
                'notifiable_id' => $recipient->id,
                'data->task_id' => $task->id,
                'data->actor_name' => $this->admin->name,
            ]);
        }
        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $this->admin->id,
            'data->task_id' => $task->id,
        ]);

        $employeeNotification = $employee->notifications()
            ->where('data->task_id', $task->id)
            ->firstOrFail();
        $this->assertSame('Updated notification task', $employeeNotification->data['task_title']);
        $this->assertStringContainsString($this->admin->name, $employeeNotification->data['body']);

        $this->actingAs($employee)
            ->get(route('tasks.index'))
            ->assertSee('Task details updated')
            ->assertSee('Updated notification task')
            ->assertSee($this->admin->name);
        $this->get(route('notifications.open', $employeeNotification))
            ->assertRedirect(route('tasks.show', $task));
    }

    public function test_user_can_add_attachments_when_updating_a_task(): void
    {
        Storage::fake('local');

        $task = Task::create([
            'task_code' => Task::generateCode(),
            'title' => 'Task receiving another file',
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'task_type' => 'process',
            'priority' => 'high',
            'status' => 'assigned',
            'original_deadline' => now()->addDays(3)->toDateString(),
            'due_date' => now()->addDays(3)->toDateString(),
            'estimated_hours' => 2,
            'expected_output' => 'Updated deliverable.',
            'created_by' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->put(route('tasks.update', $task), [
            'title' => $task->title,
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'task_type' => 'process',
            'priority' => 'high',
            'expected_output' => 'Updated deliverable.',
            'attachments' => [UploadedFile::fake()->image('updated-proof.png')],
        ]);

        $response->assertRedirect(route('tasks.index'))
            ->assertSessionHas('success', 'Task updated successfully.')
            ->assertSessionMissing('operation_status');
        $this->get(route('tasks.index'))
            ->assertSee('id="tfToast"', false)
            ->assertSee('Task updated successfully.');
        $this->assertDatabaseCount('task_attachments', 1);
        $attachment = TaskAttachment::query()->firstOrFail();
        $this->assertSame($task->id, $attachment->task_id);
        Storage::disk('local')->assertExists($attachment->path);
    }

    public function test_task_creation_rejects_unsupported_attachment_types(): void
    {
        Storage::fake('local');

        $poc = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'status' => 'active',
        ]);

        $response = $this->from(route('tasks.index'))
            ->actingAs($this->admin)
            ->followingRedirects()
            ->post(route('tasks.store'), [
                'title' => 'Task with invalid attachment',
                'company_id' => $this->company->id,
                'department_id' => $this->department->id,
                'poc_ids' => [$poc->id],
                'task_type' => 'project',
                'priority' => 'medium',
                'original_deadline' => now()->addDays(3)->toDateString(),
                'estimated_hours' => 2,
                'expected_output' => 'A deliverable.',
                'attachments' => [UploadedFile::fake()->createWithContent('script.php', '<?php echo "not allowed";')],
            ]);

        $response->assertSee('Attachments must be PDF, Office document, CSV, text, or image files.')
            ->assertSee('window.bootstrap.Modal.getOrCreateInstance(modal).show();', false);
        $this->assertDatabaseMissing('tasks', ['title' => 'Task with invalid attachment']);
        $this->assertDatabaseCount('task_attachments', 0);
    }

    public function test_task_creation_rejects_more_than_ten_attachments(): void
    {
        Storage::fake('local');

        $poc = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'status' => 'active',
        ]);
        $attachments = [];
        for ($index = 1; $index <= 11; $index++) {
            $attachments[] = UploadedFile::fake()->createWithContent("notes-{$index}.txt", 'Supporting notes.');
        }

        $response = $this->actingAs($this->admin)->post(route('tasks.store'), [
            'title' => 'Task with too many attachments',
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'poc_ids' => [$poc->id],
            'task_type' => 'project',
            'priority' => 'medium',
            'original_deadline' => now()->addDays(3)->toDateString(),
            'estimated_hours' => 2,
            'expected_output' => 'A deliverable.',
            'attachments' => $attachments,
        ]);

        $response->assertSessionHasErrors('attachments');
        $this->assertDatabaseMissing('tasks', ['title' => 'Task with too many attachments']);
        $this->assertDatabaseCount('task_attachments', 0);
    }

    public function test_task_creation_rejects_attachments_larger_than_ten_megabytes(): void
    {
        Storage::fake('local');

        $poc = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->admin)->post(route('tasks.store'), [
            'title' => 'Task with oversized attachment',
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'poc_ids' => [$poc->id],
            'task_type' => 'project',
            'priority' => 'medium',
            'original_deadline' => now()->addDays(3)->toDateString(),
            'estimated_hours' => 2,
            'expected_output' => 'A deliverable.',
            'attachments' => [UploadedFile::fake()->create('large.txt', 10241, 'text/plain')],
        ]);

        $response->assertSessionHasErrors('attachments.0');
        $this->assertDatabaseMissing('tasks', ['title' => 'Task with oversized attachment']);
        $this->assertDatabaseCount('task_attachments', 0);
    }

    public function test_user_without_task_access_cannot_download_an_attachment(): void
    {
        Storage::fake('local');

        $task = Task::create([
            'task_code' => Task::generateCode(),
            'title' => 'Private task attachment',
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'task_type' => 'process',
            'priority' => 'medium',
            'status' => 'assigned',
            'original_deadline' => now()->addDays(2)->toDateString(),
            'due_date' => now()->addDays(2)->toDateString(),
            'estimated_hours' => 1,
            'expected_output' => 'Private deliverable.',
            'created_by' => $this->admin->id,
        ]);
        $path = 'task-attachments/private.png';
        Storage::disk('local')->put($path, 'private contents');
        $attachment = $task->attachments()->create([
            'uploaded_by' => $this->admin->id,
            'disk' => 'local',
            'path' => $path,
            'original_name' => 'private.png',
            'mime_type' => 'image/png',
            'size_bytes' => 16,
        ]);
        $userWithoutTaskAccess = User::factory()->create(['status' => 'active']);

        $response = $this->actingAs($userWithoutTaskAccess)
            ->get(route('tasks.attachments.download', [$task, $attachment]));

        $response->assertForbidden();

        $this->get(route('tasks.attachments.preview', [$task, $attachment]))
            ->assertForbidden();
    }

    public function test_attachment_cannot_be_downloaded_through_a_different_task(): void
    {
        Storage::fake('local');

        $task = Task::create([
            'task_code' => Task::generateCode(),
            'title' => 'Attachment owner task',
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'task_type' => 'process',
            'priority' => 'medium',
            'status' => 'unassigned',
            'original_deadline' => now()->addDays(2)->toDateString(),
            'due_date' => now()->addDays(2)->toDateString(),
            'estimated_hours' => 1,
            'expected_output' => 'Original deliverable.',
            'created_by' => $this->admin->id,
        ]);
        $otherTask = Task::create([
            'task_code' => Task::generateCode(),
            'title' => 'Different task',
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'task_type' => 'process',
            'priority' => 'medium',
            'status' => 'assigned',
            'original_deadline' => now()->addDays(2)->toDateString(),
            'due_date' => now()->addDays(2)->toDateString(),
            'estimated_hours' => 1,
            'expected_output' => 'Another deliverable.',
            'created_by' => $this->admin->id,
        ]);
        $path = 'task-attachments/owned.png';
        Storage::disk('local')->put($path, 'private contents');
        $attachment = $task->attachments()->create([
            'uploaded_by' => $this->admin->id,
            'disk' => 'local',
            'path' => $path,
            'original_name' => 'owned.png',
            'mime_type' => 'image/png',
            'size_bytes' => 16,
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('tasks.attachments.download', [$otherTask, $attachment]));

        $response->assertNotFound();

        $this->get(route('tasks.attachments.preview', [$otherTask, $attachment]))
            ->assertNotFound();
    }

    public function test_non_previewable_attachments_are_not_served_inline(): void
    {
        Storage::fake('local');

        $task = Task::create([
            'task_code' => Task::generateCode(),
            'title' => 'Document preview restriction',
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'task_type' => 'process',
            'priority' => 'medium',
            'status' => 'unassigned',
            'original_deadline' => now()->addDays(2)->toDateString(),
            'due_date' => now()->addDays(2)->toDateString(),
            'estimated_hours' => 1,
            'expected_output' => 'Download-only document.',
            'created_by' => $this->admin->id,
        ]);
        $path = 'task-attachments/document.docx';
        Storage::disk('local')->put($path, 'document contents');
        $attachment = $task->attachments()->create([
            'uploaded_by' => $this->admin->id,
            'disk' => 'local',
            'path' => $path,
            'original_name' => 'document.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'size_bytes' => 18,
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('tasks.attachments.preview', [$task, $attachment]));

        $response->assertNotFound();
    }

    public function test_pocs_must_belong_to_the_selected_department(): void
    {
        $otherDept = Department::create([
            'company_id' => $this->company->id,
            'name' => 'Unrelated Department',
            'designation' => 'Team Member',
            'status' => 'active',
        ]);

        $invalidPoc = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $otherDept->id,
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->admin)->post(route('tasks.store'), [
            'title' => 'Task with invalid POC',
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'poc_ids' => [$invalidPoc->id],
            'task_type' => 'process',
            'priority' => 'medium',
            'original_deadline' => now()->addDays(2)->toDateString(),
            'estimated_hours' => 1,
            'expected_output' => 'A completed test deliverable.',
        ]);

        $response->assertSessionHasErrors('poc_ids');
        $this->assertDatabaseMissing('tasks', ['title' => 'Task with invalid POC']);
    }

    public function test_assignee_must_belong_to_the_selected_department(): void
    {
        $otherDepartment = Department::create([
            'company_id' => $this->company->id,
            'name' => 'Another Department',
            'designation' => 'Team Member',
            'status' => 'active',
        ]);
        $assignee = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $otherDepartment->id,
            'status' => 'active',
        ]);
        $poc = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->admin)->post(route('tasks.store'), [
            'title' => 'Department-scoped assignee',
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'assigned_to' => $assignee->id,
            'poc_ids' => [$poc->id],
            'task_type' => 'process',
            'priority' => 'medium',
            'original_deadline' => now()->addDays(2)->toDateString(),
            'estimated_hours' => 2,
            'expected_output' => 'Completed deliverable',
        ]);

        $response->assertSessionHasErrors('assigned_to');
        $this->assertDatabaseMissing('tasks', ['title' => 'Department-scoped assignee']);
    }

    public function test_task_creation_requires_estimated_effort_and_expected_output(): void
    {
        $poc = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->admin)->post(route('tasks.store'), [
            'title' => 'Missing required details',
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'poc_ids' => [$poc->id],
            'task_type' => 'process',
            'priority' => 'medium',
            'original_deadline' => now()->addDays(2)->toDateString(),
        ]);

        $response->assertSessionHasErrors(['estimated_hours', 'expected_output']);
        $this->assertDatabaseMissing('tasks', ['title' => 'Missing required details']);
    }

    public function test_task_can_be_created_without_an_assigned_employee(): void
    {
        $poc = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->admin)->post(route('tasks.store'), [
            'title' => 'Task without an assignee',
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'poc_ids' => [$poc->id],
            'task_type' => 'process',
            'priority' => 'medium',
            'original_deadline' => now()->addDays(2)->toDateString(),
            'estimated_hours' => 2,
            'expected_output' => 'Completed output with no assigned employee yet.',
        ]);

        $response->assertRedirect(route('tasks.index'))
            ->assertSessionHas('success', 'Task created successfully.');
        $this->assertDatabaseHas('tasks', [
            'title' => 'Task without an assignee',
            'assigned_to' => null,
            'status' => 'notified',
        ]);
    }

    public function test_database_supports_each_workflow_status_and_defaults_to_unassigned(): void
    {
        $persistedStatuses = [];
        foreach (Task::STATUSES as $status) {
            $task = $this->createWorkflowTask(['status' => $status]);
            $persistedStatuses[] = $task->fresh()->status;
        }

        $defaultStatusTask = $this->createWorkflowTask();

        $this->assertSame(Task::STATUSES, $persistedStatuses);
        $this->assertSame('unassigned', $defaultStatusTask->fresh()->status);
    }

    public function test_legacy_task_migration_preserves_assignments_and_original_history(): void
    {
        $assignee = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'status' => 'active',
        ]);
        $assignedTask = $this->createWorkflowTask([
            'assigned_to' => $assignee->id,
            'status' => 'unassigned',
        ]);
        $unassignedTask = $this->createWorkflowTask(['status' => 'unassigned']);
        TaskStatusHistory::create([
            'task_id' => $assignedTask->id,
            'changed_by' => $this->admin->id,
            'new_status' => 'todo',
            'remarks' => 'Initial legacy task status.',
        ]);
        TaskStatusHistory::create([
            'task_id' => $unassignedTask->id,
            'changed_by' => $this->admin->id,
            'new_status' => 'todo',
            'remarks' => 'Initial legacy task status.',
        ]);
        DB::unprepared('DROP TRIGGER IF EXISTS tasks_status_supported_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS tasks_status_supported_update');
        DB::table('tasks')->whereIn('id', [$assignedTask->id, $unassignedTask->id])->update(['status' => 'todo']);
        $this->assertSame(
            $assignee->id,
            DB::table('tasks')->where('id', $assignedTask->id)->value('assigned_to'),
        );

        $migration = require database_path('migrations/2026_10_22_062902_update_task_status_workflow_and_history.php');
        $migration->up();

        $databaseDefaultTaskId = DB::table('tasks')->insertGetId([
            'task_code' => Task::generateCode(),
            'title' => 'Database-default status task',
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'task_type' => 'process',
            'estimated_hours' => 2,
            'due_date' => now()->addDays(10)->toDateString(),
            'original_deadline' => now()->addDays(10)->toDateString(),
            'expected_output' => 'A task inserted without an explicit status.',
            'created_by' => $this->admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame('assigned', $assignedTask->fresh()->status);
        $this->assertSame('unassigned', $unassignedTask->fresh()->status);
        $this->assertSame(
            'unassigned',
            DB::table('tasks')->where('id', $databaseDefaultTaskId)->value('status'),
        );
        $this->assertDatabaseHas('task_assignments', [
            'task_id' => $assignedTask->id,
            'user_id' => $assignee->id,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('task_status_histories', [
            'task_id' => $assignedTask->id,
            'new_status' => 'assigned',
        ]);
        $this->assertDatabaseHas('task_status_histories', [
            'task_id' => $unassignedTask->id,
            'new_status' => 'unassigned',
        ]);
        $this->assertStringContainsString(
            '[Original status: todo]',
            (string) $assignedTask->statusHistory()->latest('id')->value('remarks'),
        );

        try {
            $migration->down();
            $this->fail('The workflow migration should refuse rollback when workflow data would be lost.');
        } catch (\RuntimeException $exception) {
            $this->assertSame(
                'Task workflow data has been recorded. Export or migrate that data before rolling back this migration.',
                $exception->getMessage(),
            );
        }

        $this->assertSame('assigned', $assignedTask->fresh()->status);
        $this->assertDatabaseHas('task_assignments', [
            'task_id' => $assignedTask->id,
            'user_id' => $assignee->id,
            'is_active' => true,
        ]);
        $this->assertTrue(Schema::hasColumn('tasks', 'hold_reason'));
        $this->assertTrue(Schema::hasColumn('task_status_histories', 'event_type'));
    }

    public function test_status_endpoint_rejects_manually_setting_derived_overdue_status(): void
    {
        $task = $this->createWorkflowTask(['status' => 'assigned']);

        $response = $this->actingAs($this->admin)
            ->post(route('tasks.status.update', $task), ['status' => 'overdue']);

        $response->assertSessionHasErrors('status');
        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'status' => 'assigned',
        ]);
    }

    public function test_deadline_status_migration_normalizes_status_and_preserves_assignment_and_history(): void
    {
        $assignee = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'status' => 'active',
        ]);
        $task = $this->createWorkflowTask([
            'assigned_to' => $assignee->id,
            'status' => 'in_progress',
        ]);
        TaskAssignment::create([
            'task_id' => $task->id,
            'user_id' => $assignee->id,
            'assigned_by' => $this->admin->id,
            'reason' => 'Initial assignment',
            'assigned_at' => now(),
            'is_active' => true,
        ]);
        $history = TaskStatusHistory::create([
            'task_id' => $task->id,
            'changed_by' => $this->admin->id,
            'old_status' => 'in_progress',
            'new_status' => 'at_risk',
            'event_type' => 'risk',
            'remarks' => 'Flagged at risk: a vendor delay.',
        ]);

        DB::unprepared('DROP TRIGGER IF EXISTS tasks_status_supported_update');
        DB::table('tasks')->where('id', $task->id)->update(['status' => 'at_risk']);
        $migration = require database_path('migrations/2026_10_22_093928_normalize_task_statuses_and_deadline_conditions.php');
        $migration->up();

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'status' => 'in_progress',
            'assigned_to' => $assignee->id,
        ]);
        $this->assertDatabaseHas('task_assignments', [
            'task_id' => $task->id,
            'user_id' => $assignee->id,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('task_status_histories', [
            'id' => $history->id,
            'changed_by' => $this->admin->id,
            'old_status' => 'in_progress',
            'new_status' => 'in_progress',
            'event_type' => 'risk',
        ]);
        $this->assertStringContainsString(
            '[Original deadline condition: at_risk]',
            (string) $history->fresh()->remarks,
        );

        $migration->down();
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'status' => 'in_progress']);
        $this->assertDatabaseHas('task_status_histories', ['id' => $history->id, 'event_type' => 'risk']);
    }

    public function test_non_poc_cannot_accept_a_notified_task(): void
    {
        $employee = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'status' => 'active',
        ]);
        $employee->assignRole('Employee');
        $task = $this->createWorkflowTask(['status' => 'notified']);

        $this->actingAs($employee)
            ->post(route('tasks.accept', $task))
            ->assertForbidden();

        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'status' => 'notified']);
    }

    public function test_resuming_an_held_assigned_task_does_not_start_work(): void
    {
        $employee = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'status' => 'active',
        ]);
        $employee->assignRole('Employee');
        $task = $this->createWorkflowTask([
            'assigned_to' => $employee->id,
            'status' => 'assigned',
        ]);
        TaskAssignment::create([
            'task_id' => $task->id,
            'user_id' => $employee->id,
            'assigned_by' => $this->admin->id,
            'reason' => 'Initial assignment',
            'assigned_at' => now(),
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)->post(route('tasks.status.update', $task), [
            'status' => 'on_hold',
            'reason' => 'Waiting on approval.',
            'update_date' => '2026-06-14',
        ])->assertRedirect(route('tasks.show', $task));
        $this->actingAs($employee)
            ->get(route('tasks.show', $task))
            ->assertOk()
            ->assertSee('Unhold')
            ->assertDontSee('Choose next status');
        $this->post(route('tasks.resume', $task))->assertRedirect(route('tasks.show', $task));

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'status' => 'assigned',
            'start_date' => null,
        ]);
    }

    public function test_status_endpoint_rejects_an_unsupported_status(): void
    {
        $task = $this->createWorkflowTask(['status' => 'assigned']);

        $response = $this->actingAs($this->admin)
            ->post(route('tasks.status.update', $task), ['status' => 'not_a_status']);

        $response->assertSessionHasErrors('status');
        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'status' => 'assigned',
        ]);
    }

    public function test_user_without_workflow_permission_cannot_change_task_status(): void
    {
        $unauthorizedUser = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'status' => 'active',
        ]);
        $task = $this->createWorkflowTask(['status' => 'unassigned']);

        $this->actingAs($unauthorizedUser)
            ->post(route('tasks.status.update', $task), ['status' => 'assigned'])
            ->assertForbidden();

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'status' => 'unassigned',
        ]);
    }

    public function test_poc_acceptance_assignment_start_and_work_update_follow_the_lifecycle(): void
    {
        Storage::fake('local');
        $poc = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'status' => 'active',
        ]);
        $poc->assignRole('Team Leader');
        $employee = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'status' => 'active',
        ]);
        $employee->assignRole('Employee');
        $task = $this->createWorkflowTask(['status' => 'notified']);
        $task->pocs()->attach($poc->id);

        $this->actingAs($poc)
            ->post(route('tasks.accept', $task))
            ->assertRedirect(route('tasks.show', $task));
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'status' => 'unassigned']);
        $this->get(route('tasks.show', $task))
            ->assertOk()
            ->assertSee('data-reassign-auto-open', false)
            ->assertSee('id="reassignTaskModal"', false)
            ->assertDontSee('Task accepted and ready for employee assignment.', false);
        $this->assertDatabaseHas('task_status_histories', [
            'task_id' => $task->id,
            'old_status' => 'notified',
            'new_status' => 'unassigned',
            'event_type' => 'accepted',
            'changed_by' => $poc->id,
        ]);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $this->admin->id,
            'data->task_id' => $task->id,
        ]);

        $response = $this->actingAs($poc)->post(route('tasks.reassign', $task), [
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'assigned_to' => [$employee->id],
        ]);
        $response->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('task_assignments', [
            'task_id' => $task->id,
            'user_id' => $employee->id,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'status' => 'assigned']);

        $this->actingAs($employee)
            ->post(route('tasks.start', $task), [
                'note' => 'Beginning implementation.',
                'start_date' => '2026-06-13',
                'attachments' => [UploadedFile::fake()->image('start.png')],
            ])
            ->assertRedirect(route('tasks.show', $task));
        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'status' => 'in_progress',
            'start_date' => '2026-06-13 00:00:00',
        ]);
        $this->assertDatabaseHas('task_status_histories', [
            'task_id' => $task->id,
            'old_status' => 'assigned',
            'new_status' => 'in_progress',
            'event_type' => 'start',
            'changed_by' => $employee->id,
            'remarks' => 'Beginning implementation.',
        ]);
        $startHistory = TaskStatusHistory::query()
            ->where('task_id', $task->id)
            ->where('event_type', 'start')
            ->firstOrFail();
        $this->assertSame('2026-06-13', $startHistory->created_at->toDateString());
        $startAttachment = TaskAttachment::query()->where('task_id', $task->id)->firstOrFail();
        Storage::disk('local')->assertExists($startAttachment->path);
        $this->assertSame($startHistory->id, $startAttachment->status_history_id);
        $this->actingAs($employee)
            ->get(route('tasks.show', $task))
            ->assertOk()
            ->assertSee('start.png')
            ->assertSee('data-wf-toggle="top-hold"', false)
            ->assertSee('Review')
            ->assertSee('Recent updates and history')
            ->assertDontSee('tf-task-detail__activity-tabs', false)
            ->assertDontSee('data-task-activity-tab', false)
            ->assertSee('class="tf-task-detail__people-cards"', false)
            ->assertSee('Department Points of Contact')
            ->assertSee('id="task-assignment-title"', false)
            ->assertSee('id="topTaskWorkUpdate" name="note"', false)
            ->assertSee('id="topWorkUpdateDate" name="update_date"', false)
            ->assertSee('data-wf-toggle="top-submit-review"', false)
            ->assertSee('name="status" value="submitted_for_review"', false)
            ->assertSee('id="topTaskSubmissionNote" name="reason"', false)
            ->assertSee('id="topTaskSubmissionDate" name="update_date"', false)
            ->assertSee('id="topTaskSubmissionFiles" name="attachments[]"', false)
            ->assertDontSee('topWorkUpdateTitle', false)
            ->assertDontSee('Choose next status')
            ->assertSee('Cancel Task')
            ->assertSee('name="status" value="cancelled"', false);

        $this->post(route('tasks.work-updates.store', $task), [
            'note' => 'Completed the first milestone.',
            'update_date' => '2026-06-14',
            'attachments' => [UploadedFile::fake()->image('milestone.png')],
        ])->assertRedirect(route('tasks.show', $task));
        $this->assertDatabaseHas('task_status_histories', [
            'task_id' => $task->id,
            'old_status' => 'in_progress',
            'new_status' => 'in_progress',
            'event_type' => 'work_update',
            'remarks' => 'Completed the first milestone.',
        ]);
        $workUpdate = TaskStatusHistory::query()
            ->where('task_id', $task->id)
            ->where('event_type', 'work_update')
            ->firstOrFail();
        $this->assertSame('2026-06-14', $workUpdate->created_at->toDateString());
        $attachment = TaskAttachment::query()->where('task_id', $task->id)->firstOrFail();
        Storage::disk('local')->assertExists($attachment->path);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $poc->id,
            'data->task_id' => $task->id,
        ]);
    }

    public function test_work_update_rejects_an_invalid_update_date(): void
    {
        $task = $this->createWorkflowTask(['status' => 'in_progress']);

        $this->post(route('tasks.work-updates.store', $task), [
            'note' => 'A valid work update.',
            'update_date' => 'not-a-date',
        ])
            ->assertRedirect()
            ->assertSessionHasErrors('update_date');

        $this->assertDatabaseMissing('task_status_histories', [
            'task_id' => $task->id,
            'event_type' => 'work_update',
        ]);
    }

    public function test_resuming_a_held_task_restores_its_previous_workflow_status(): void
    {
        Storage::fake('local');
        $employee = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'status' => 'active',
        ]);
        $employee->assignRole('Employee');
        $task = $this->createWorkflowTask([
            'assigned_to' => $employee->id,
            'status' => 'in_progress',
        ]);
        TaskAssignment::create([
            'task_id' => $task->id,
            'user_id' => $employee->id,
            'assigned_by' => $this->admin->id,
            'reason' => 'Initial assignment',
            'assigned_at' => now(),
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->post(route('tasks.status.update', $task), [
                'status' => 'on_hold',
                'reason' => 'Waiting for required access.',
                'update_date' => '2026-06-14',
                'attachments' => [UploadedFile::fake()->image('hold.png')],
            ])->assertRedirect(route('tasks.show', $task));
        $holdHistory = TaskStatusHistory::query()
            ->where('task_id', $task->id)
            ->where('event_type', 'hold')
            ->firstOrFail();
        $this->assertSame('2026-06-14', $holdHistory->created_at->toDateString());
        $holdAttachment = TaskAttachment::query()->where('task_id', $task->id)->firstOrFail();
        Storage::disk('local')->assertExists($holdAttachment->path);
        $this->assertSame($holdHistory->id, $holdAttachment->status_history_id);
        $this->get(route('tasks.show', $task))
            ->assertOk()
            ->assertSee('hold.png')
            ->assertSee('Image attachment')
            ->assertSeeInOrder([
                'put the task',
                '<span class="tf-task-detail__timeline-badge tf-task-detail__timeline-badge--on-hold">On Hold</span>',
            ], false)
            ->assertSee('<span class="tf-task-detail__timeline-badge tf-task-detail__timeline-badge--created">Created</span>', false)
            ->assertSee('<span class="tf-task-detail__timeline-badge tf-task-detail__timeline-badge--assigned">Assigned</span>', false)
            ->assertSee('Unhold')
            ->assertDontSee('Put on Hold');
        $this->actingAs($employee)
            ->post(route('tasks.resume', $task), ['note' => 'Access was restored.'])
            ->assertRedirect(route('tasks.show', $task));

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'status' => 'in_progress',
            'hold_reason' => null,
        ]);
        $this->assertDatabaseHas('task_status_histories', [
            'task_id' => $task->id,
            'old_status' => 'on_hold',
            'new_status' => 'in_progress',
            'event_type' => 'resume',
        ]);
        $this->get(route('tasks.show', $task))
            ->assertSee('<span class="tf-task-detail__timeline-badge tf-task-detail__timeline-badge--in-progress">In Progress</span>', false);
        $this->assertStringContainsString(
            'Previous hold reason: Waiting for required access.',
            (string) $task->statusHistory()->where('event_type', 'resume')->value('remarks'),
        );
    }

    public function test_on_hold_requires_a_reason_and_records_it(): void
    {
        $assignee = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'status' => 'active',
        ]);
        $task = $this->createWorkflowTask([
            'assigned_to' => $assignee->id,
            'status' => 'assigned',
        ]);
        TaskAssignment::create([
            'task_id' => $task->id,
            'user_id' => $assignee->id,
            'assigned_by' => $this->admin->id,
            'reason' => 'Initial assignment',
            'assigned_at' => now(),
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->post(route('tasks.status.update', $task), ['status' => 'in_progress'])
            ->assertSessionHasErrors('status');
        $this->assertSame('assigned', $task->fresh()->status);

        $this->actingAs($this->admin)
            ->post(route('tasks.status.update', $task), ['status' => 'on_hold'])
            ->assertSessionHasErrors(['reason', 'update_date']);

        $this->post(route('tasks.status.update', $task), [
            'status' => 'on_hold',
            'reason' => 'Waiting for the client review.',
            'update_date' => '2026-06-14',
        ])->assertRedirect(route('tasks.show', $task));

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'status' => 'on_hold',
            'hold_reason' => 'Waiting for the client review.',
        ]);
    }

    public function test_submission_and_authorized_review_complete_the_task(): void
    {
        Storage::fake('local');
        $employeeRole = Role::query()->where('name', 'Employee')->firstOrFail();
        $assignee = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'status' => 'active',
        ]);
        $assignee->syncRoles([$employeeRole]);
        $task = $this->createWorkflowTask([
            'assigned_to' => $assignee->id,
            'reviewer_id' => $this->admin->id,
            'status' => 'in_progress',
        ]);
        TaskAssignment::create([
            'task_id' => $task->id,
            'user_id' => $assignee->id,
            'assigned_by' => $this->admin->id,
            'reason' => 'Initial assignment',
            'assigned_at' => now(),
            'is_active' => true,
        ]);

        $this->actingAs($assignee)
            ->post(route('tasks.status.update', $task), ['status' => 'submitted_for_review'])
            ->assertSessionHasErrors(['reason', 'update_date']);
        $this->assertSame('in_progress', $task->fresh()->status);

        $this->post(route('tasks.status.update', $task), [
            'status' => 'submitted_for_review',
            'reason' => 'Ready for acceptance.',
            'update_date' => '2026-06-15',
            'attachments' => [UploadedFile::fake()->image('completion.png')],
        ])
            ->assertRedirect(route('tasks.show', $task));

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'status' => 'submitted_for_review',
        ]);
        $this->assertSame('2026-06-15', $task->fresh()->submitted_at->toDateString());
        $submission = TaskStatusHistory::query()
            ->where('task_id', $task->id)
            ->where('event_type', 'submission')
            ->firstOrFail();
        $this->assertSame('2026-06-15', $submission->created_at->toDateString());
        $submissionAttachment = TaskAttachment::query()->where('task_id', $task->id)->firstOrFail();
        Storage::disk('local')->assertExists($submissionAttachment->path);
        $this->assertSame($submission->id, $submissionAttachment->status_history_id);

        $this->actingAs($this->admin)
            ->get(route('tasks.show', $task))
            ->assertSee('completion.png')
            ->assertSee('name="decision" value="approve"', false)
            ->assertSee('Approve')
            ->assertSee('name="decision" value="rework"', false)
            ->assertSee('Re-work')
            ->assertDontSee('<span class="tf-task-workflow__step-label">Re-work</span>', false)
            ->assertDontSee('Review Submission')
            ->assertDontSee('Request Changes')
            ->assertDontSee('Work Done')
            ->assertDontSee('Start Work');

        $response = $this->actingAs($this->admin)
            ->followingRedirects()
            ->post(route('tasks.review', $task), [
                'decision' => 'approve',
                'comment' => 'Acceptance criteria met.',
            ]);
        $response
            ->assertOk()
            ->assertSee('"title":"Task approved"', false)
            ->assertSee('"message":"Task approved and completed."', false)
            ->assertDontSee('id="tfToast"', false)
            ->assertDontSee('tf-operation__close', false)
            ->assertDontSee('>Done</button>', false);
        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'status' => 'completed',
            'reviewed_by' => $this->admin->id,
            'review_comment' => 'Acceptance criteria met.',
        ]);
    }

    public function test_assigned_employee_only_can_start_work_and_start_details_are_required(): void
    {
        $employee = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'status' => 'active',
        ]);
        $employee->assignRole('Employee');
        $task = $this->createWorkflowTask([
            'assigned_to' => $employee->id,
            'status' => 'assigned',
        ]);
        TaskAssignment::create([
            'task_id' => $task->id,
            'user_id' => $employee->id,
            'assigned_by' => $this->admin->id,
            'reason' => 'Initial assignment',
            'assigned_at' => now(),
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->get(route('tasks.show', $task))
            ->assertDontSee('data-wf-toggle="top-start"', false);
        $this->actingAs($employee)
            ->get(route('tasks.show', $task))
            ->assertSee('data-wf-toggle="top-start"', false)
            ->assertSee('id="topTaskStartNote" name="note"', false)
            ->assertSee('id="topTaskStartDate" name="start_date"', false);

        $this->post(route('tasks.start', $task))
            ->assertSessionHasErrors(['note', 'start_date']);
        $this->assertSame('assigned', $task->fresh()->status);

        $this->actingAs($this->admin)
            ->post(route('tasks.start', $task), [
                'note' => 'Unauthorized start.',
                'start_date' => '2026-06-13',
            ])
            ->assertForbidden();
        $this->assertSame('assigned', $task->fresh()->status);
    }

    public function test_assigned_employee_can_cancel_an_in_progress_task_with_a_reason(): void
    {
        $employee = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'status' => 'active',
        ]);
        $employee->assignRole('Employee');
        $task = $this->createWorkflowTask([
            'assigned_to' => $employee->id,
            'status' => 'in_progress',
        ]);
        TaskAssignment::create([
            'task_id' => $task->id,
            'user_id' => $employee->id,
            'assigned_by' => $this->admin->id,
            'reason' => 'Initial assignment',
            'assigned_at' => now(),
            'is_active' => true,
        ]);

        $this->actingAs($employee)
            ->post(route('tasks.status.update', $task), [
                'status' => 'cancelled',
                'reason' => 'The work is no longer required.',
            ])
            ->assertRedirect(route('tasks.show', $task));

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'status' => 'cancelled',
            'cancellation_reason' => 'The work is no longer required.',
            'cancelled_by' => $employee->id,
        ]);
    }

    public function test_employee_can_send_rework_back_for_review(): void
    {
        $employeeRole = Role::query()->where('name', 'Employee')->firstOrFail();
        $assignee = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'status' => 'active',
        ]);
        $assignee->syncRoles([$employeeRole]);

        $task = $this->createWorkflowTask([
            'assigned_to' => $assignee->id,
            'reviewer_id' => $this->admin->id,
            'status' => 'submitted_for_review',
            'progress_percentage' => 80,
        ]);
        TaskAssignment::create([
            'task_id' => $task->id,
            'user_id' => $assignee->id,
            'assigned_by' => $this->admin->id,
            'reason' => 'Initial assignment',
            'assigned_at' => now(),
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->post(route('tasks.review', $task), ['decision' => 'rework'])
            ->assertRedirect(route('tasks.show', $task));

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'status' => 'rework',
            'progress_percentage' => 80,
            'reviewed_by' => $this->admin->id,
            'completed_at' => null,
        ]);
        $this->assertDatabaseHas('task_status_histories', [
            'task_id' => $task->id,
            'old_status' => 'submitted_for_review',
            'new_status' => 'rework',
            'event_type' => 'review',
            'changed_by' => $this->admin->id,
        ]);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $assignee->id,
            'data->task_id' => $task->id,
        ]);

        $this->actingAs($assignee)
            ->get(route('tasks.show', $task))
            ->assertSee('Work Done')
            ->assertSee('<span class="tf-task-workflow__step-label">Re-work</span>', false)
            ->assertSee('data-wf-toggle="top-hold"', false)
            ->assertSee('name="status" value="submitted_for_review"', false);

        $this->post(route('tasks.start', $task))
            ->assertForbidden();

        $this->post(route('tasks.status.update', $task), [
            'status' => 'submitted_for_review',
            'reason' => 'Re-work has been completed.',
            'update_date' => '2026-06-15',
        ])
            ->assertRedirect(route('tasks.show', $task));

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'status' => 'submitted_for_review',
            'reviewed_by' => null,
            'reviewed_at' => null,
        ]);
        $this->assertNotNull($task->fresh()->submitted_at);
        $this->assertDatabaseHas('task_status_histories', [
            'task_id' => $task->id,
            'old_status' => 'rework',
            'new_status' => 'submitted_for_review',
            'event_type' => 'submission',
        ]);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $this->admin->id,
            'data->task_id' => $task->id,
        ]);
    }

    public function test_reviewer_can_put_a_submission_on_hold_and_resume_it_to_review(): void
    {
        $employee = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'status' => 'active',
        ]);
        $employee->assignRole('Employee');
        $reviewer = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'status' => 'active',
        ]);
        $reviewer->givePermissionTo(['tasks.view', 'tasks.review']);
        $task = $this->createWorkflowTask([
            'assigned_to' => $employee->id,
            'reviewer_id' => $reviewer->id,
            'status' => 'submitted_for_review',
        ]);
        $task->pocs()->attach($reviewer->id);
        TaskAssignment::create([
            'task_id' => $task->id,
            'user_id' => $employee->id,
            'assigned_by' => $this->admin->id,
            'reason' => 'Initial assignment',
            'assigned_at' => now(),
            'is_active' => true,
        ]);

        $this->actingAs($employee)
            ->post(route('tasks.status.update', $task), [
                'status' => 'on_hold',
                'reason' => 'Trying to hold a review task.',
                'update_date' => '2026-06-14',
            ])
            ->assertForbidden();

        $this->actingAs($reviewer)
            ->get(route('tasks.show', $task))
            ->assertOk()
            ->assertSee('data-wf-toggle="top-hold"', false)
            ->assertSee('name="decision" value="approve"', false);

        $this->post(route('tasks.status.update', $task), [
            'status' => 'on_hold',
            'reason' => 'Waiting for review materials.',
            'update_date' => '2026-06-14',
        ])->assertRedirect(route('tasks.show', $task));

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'status' => 'on_hold',
            'hold_reason' => 'Waiting for review materials.',
        ]);
        $this->assertDatabaseHas('task_status_histories', [
            'task_id' => $task->id,
            'old_status' => 'submitted_for_review',
            'new_status' => 'on_hold',
            'event_type' => 'hold',
            'changed_by' => $reviewer->id,
        ]);

        $this->actingAs($reviewer)
            ->get(route('tasks.show', $task))
            ->assertSee('Unhold')
            ->assertDontSee('Back to Review');

        $this->post(route('tasks.resume', $task))
            ->assertRedirect(route('tasks.show', $task));

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'status' => 'submitted_for_review',
            'hold_reason' => null,
        ]);
        $this->assertDatabaseHas('task_status_histories', [
            'task_id' => $task->id,
            'old_status' => 'on_hold',
            'new_status' => 'submitted_for_review',
            'event_type' => 'resume',
        ]);
    }

    public function test_team_leader_can_submit_a_department_task_for_review(): void
    {
        $teamLeader = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'status' => 'active',
        ]);
        $teamLeader->assignRole('Team Leader');

        $assignee = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'status' => 'active',
        ]);
        $assignee->assignRole('Employee');

        $task = $this->createWorkflowTask([
            'assigned_to' => $assignee->id,
            'reviewer_id' => $this->admin->id,
            'status' => 'in_progress',
        ]);
        TaskAssignment::create([
            'task_id' => $task->id,
            'user_id' => $assignee->id,
            'assigned_by' => $teamLeader->id,
            'reason' => 'Department assignment',
            'assigned_at' => now(),
            'is_active' => true,
        ]);

        $this->actingAs($teamLeader)
            ->get(route('tasks.show', $task))
            ->assertSee('class="tf-wf-action-btn tf-wf-action-btn--review"', false)
            ->assertSee('name="status" value="submitted_for_review"', false);

        $this->post(route('tasks.status.update', $task), [
            'status' => 'submitted_for_review',
            'reason' => 'The task is complete.',
            'update_date' => '2026-06-15',
        ])
            ->assertRedirect(route('tasks.show', $task))
            ->assertSessionHas('operation_status.kind', 'task')
            ->assertSessionHas('operation_status.title', 'Task status updated')
            ->assertSessionHas('operation_status.message', 'Task status updated to Submitted for Review.');

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'status' => 'submitted_for_review',
            'reviewer_id' => $this->admin->id,
        ]);
        $this->assertDatabaseHas('task_status_histories', [
            'task_id' => $task->id,
            'old_status' => 'in_progress',
            'new_status' => 'submitted_for_review',
            'event_type' => 'submission',
            'changed_by' => $teamLeader->id,
        ]);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $this->admin->id,
            'data->task_id' => $task->id,
        ]);
    }

    public function test_authorized_cancellation_requires_and_records_a_reason(): void
    {
        $task = $this->createWorkflowTask(['status' => 'unassigned']);

        $this->actingAs($this->admin)
            ->post(route('tasks.status.update', $task), ['status' => 'cancelled'])
            ->assertSessionHasErrors('reason');

        $this->post(route('tasks.status.update', $task), [
            'status' => 'cancelled',
            'reason' => 'The request is no longer needed.',
        ])->assertRedirect(route('tasks.show', $task));

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'status' => 'cancelled',
            'cancellation_reason' => 'The request is no longer needed.',
            'cancelled_by' => $this->admin->id,
        ]);
    }

    public function test_overdue_is_derived_from_the_effective_deadline(): void
    {
        $this->travelTo(now()->startOfDay());
        $deadline = today()->subDay()->toDateString();
        $task = $this->createWorkflowTask([
            'status' => 'in_progress',
            'due_date' => $deadline,
            'original_deadline' => $deadline,
        ]);

        $this->assertSame('in_progress', $task->fresh()->status);
        $this->assertSame('in_progress', $task->fresh()->display_status);
        $this->assertSame('overdue', $task->fresh()->deadline_condition);
        $this->assertTrue(Task::query()->overdue()->whereKey($task->id)->exists());
    }

    public function test_flagging_and_resolving_risk_preserves_status_history_and_prior_status(): void
    {
        $assignee = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'status' => 'active',
        ]);
        $task = $this->createWorkflowTask([
            'assigned_to' => $assignee->id,
            'status' => 'in_progress',
        ]);
        TaskAssignment::create([
            'task_id' => $task->id,
            'user_id' => $assignee->id,
            'assigned_by' => $this->admin->id,
            'reason' => 'Initial assignment',
            'assigned_at' => now(),
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->post(route('tasks.risk.update', $task), [
                'action' => 'flag',
                'reason' => 'A dependency may delay delivery.',
            ])
            ->assertRedirect(route('tasks.show', $task));

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'status' => 'in_progress',
            'risk_reason' => 'A dependency may delay delivery.',
            'risk_flagged_by' => $this->admin->id,
        ]);
        $this->assertDatabaseHas('task_status_histories', [
            'task_id' => $task->id,
            'old_status' => 'in_progress',
            'new_status' => 'in_progress',
            'event_type' => 'risk',
        ]);

        $this->post(route('tasks.risk.update', $task), ['action' => 'resolve'])
            ->assertRedirect(route('tasks.show', $task));

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'status' => 'in_progress',
            'risk_reason' => null,
            'risk_flagged_by' => null,
        ]);
        $this->assertDatabaseHas('task_status_histories', [
            'task_id' => $task->id,
            'old_status' => 'in_progress',
            'new_status' => 'in_progress',
            'event_type' => 'risk',
        ]);
    }

    public function test_original_deadline_is_immutable_on_update(): void
    {
        $originalDate = now()->addDays(3)->toDateString();
        $task = Task::create([
            'task_code' => Task::generateCode(),
            'title' => 'Immutable Deadline Test',
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'task_type' => 'volume',
            'priority' => 'high',
            'status' => 'assigned',
            'original_deadline' => $originalDate,
            'due_date' => $originalDate,
            'estimated_hours' => 3,
            'expected_output' => 'A completed test deliverable.',
            'created_by' => $this->admin->id,
        ]);

        $newDueDate = now()->addDays(10)->toDateString();
        $tamperedOriginalDeadline = now()->addDays(20)->toDateString();

        $response = $this->actingAs($this->admin)->put(route('tasks.update', $task), [
            'title' => 'Immutable Deadline Test (Updated)',
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'task_type' => 'volume',
            'priority' => 'high',
            'status' => 'in_progress',
            'original_deadline' => $tamperedOriginalDeadline,
            'due_date' => $newDueDate,
        ]);

        $response->assertRedirect(route('tasks.index'));
        $task->refresh();

        $this->assertSame($originalDate, $task->original_deadline->toDateString());
        $this->assertSame($newDueDate, $task->due_date->toDateString());
        $this->assertSame('assigned', $task->status);
    }

    public function test_task_can_be_updated_without_status_field_from_edit_modal(): void
    {
        $poc = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'status' => 'active',
        ]);
        $assignee = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'status' => 'active',
        ]);
        $task = Task::create([
            'task_code' => Task::generateCode(),
            'title' => 'Initial Title',
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'assigned_to' => null,
            'task_type' => 'process',
            'priority' => 'low',
            'status' => 'in_progress',
            'original_deadline' => now()->addDays(5)->toDateString(),
            'due_date' => now()->addDays(5)->toDateString(),
            'estimated_hours' => 3.5,
            'expected_output' => 'Initial deliverable criteria',
            'created_by' => $this->admin->id,
        ]);
        $task->pocs()->attach($poc->id);

        $response = $this->actingAs($this->admin)
            ->followingRedirects()
            ->put(route('tasks.update', $task), [
                'title' => 'Updated via Edit Modal',
                'company_id' => $this->company->id,
                'department_id' => $this->department->id,
                'assigned_to' => $assignee->id,
                'poc_ids' => [$poc->id],
                'task_type' => 'project',
                'priority' => 'critical',
                'estimated_hours' => 7.5,
                'expected_output' => 'Updated deliverable criteria',
            ]);

        $response->assertOk()
            ->assertSee('id="tfToast"', false)
            ->assertSee('Task updated successfully.')
            ->assertDontSee('"kind":"task"', false);

        $task->refresh();
        $this->assertSame('Updated via Edit Modal', $task->title);
        $this->assertSame('project', $task->task_type);
        $this->assertSame('critical', $task->priority);
        $this->assertSame('7.50', (string) $task->estimated_hours);
        $this->assertSame('Updated deliverable criteria', $task->expected_output);
        $this->assertSame($assignee->id, $task->assigned_to);
        $this->assertSame('in_progress', $task->status);
    }

    public function test_updating_task_assignees_preserves_history_and_updates_primary_assignee(): void
    {
        $firstAssignee = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'status' => 'active',
        ]);
        $secondAssignee = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'status' => 'active',
        ]);
        $replacementAssignee = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'status' => 'active',
        ]);
        $task = Task::create([
            'task_code' => Task::generateCode(),
            'title' => 'Task with multiple assignees',
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'assigned_to' => $firstAssignee->id,
            'task_type' => 'process',
            'priority' => 'medium',
            'status' => 'unassigned',
            'original_deadline' => now()->addDays(5)->toDateString(),
            'due_date' => now()->addDays(5)->toDateString(),
            'estimated_hours' => 3,
            'expected_output' => 'Complete the task.',
            'created_by' => $this->admin->id,
        ]);
        $task->assignments()->create([
            'user_id' => $firstAssignee->id,
            'assigned_by' => $this->admin->id,
            'reason' => 'Initial assignment',
            'assigned_at' => now()->subDay(),
            'is_active' => true,
        ]);
        $task->assignments()->create([
            'user_id' => $secondAssignee->id,
            'assigned_by' => $this->admin->id,
            'reason' => 'Initial assignment',
            'assigned_at' => now()->subDay(),
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin)->put(route('tasks.update', $task), [
            'title' => $task->title,
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'assigned_to' => [$secondAssignee->id, $replacementAssignee->id],
            'poc_ids' => [],
            'task_type' => 'process',
            'priority' => 'medium',
            'estimated_hours' => 3,
            'expected_output' => 'Complete the task.',
        ]);

        $response->assertRedirect(route('tasks.index'))
            ->assertSessionHas('success', 'Task updated successfully.');
        $task->refresh();
        $this->assertSame($secondAssignee->id, $task->assigned_to);
        $this->assertEqualsCanonicalizing(
            [$secondAssignee->id, $replacementAssignee->id],
            $task->activeAssignees()->pluck('users.id')->all(),
        );
        $this->assertDatabaseHas('task_assignments', [
            'task_id' => $task->id,
            'user_id' => $firstAssignee->id,
            'is_active' => false,
        ]);
        $this->assertDatabaseHas('task_assignments', [
            'task_id' => $task->id,
            'user_id' => $secondAssignee->id,
            'is_active' => true,
        ]);
    }

    public function test_ajax_users_by_department_returns_active_users(): void
    {
        $ceoRole = Role::query()->where('name', 'CEO')->where('guard_name', 'web')->firstOrFail();
        $employeeRole = Role::query()->where('name', 'Employee')->where('guard_name', 'web')->firstOrFail();
        $teamLeaderRole = Role::query()->where('name', 'Team Leader')->where('guard_name', 'web')->firstOrFail();
        $ceo = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'status' => 'active',
            'name' => 'Active CEO',
            'role_id' => $ceoRole->id,
        ]);
        $employee = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'status' => 'active',
            'name' => 'Active Employee',
            'role_id' => $employeeRole->id,
        ]);
        $teamLeader = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'status' => 'active',
            'name' => 'Active Team Leader',
            'role_id' => $teamLeaderRole->id,
        ]);
        User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'status' => 'inactive',
            'name' => 'Inactive Member',
        ]);

        $response = $this->actingAs($this->admin)->getJson(route('tasks.users-by-department', $this->department));

        $response->assertOk();
        $employeeIds = array_column($response->json('employees'), 'id');
        $pocIds = array_column($response->json('pocs'), 'id');

        $this->assertContains($ceo->id, $employeeIds);
        $this->assertContains($employee->id, $employeeIds);
        $this->assertContains($teamLeader->id, $employeeIds);
        $this->assertContains($ceo->id, $pocIds);
        $this->assertContains($employee->id, $pocIds);
        $this->assertContains($teamLeader->id, $pocIds);
        $this->assertNotContains(
            User::query()->where('name', 'Inactive Member')->value('id'),
            $employeeIds
        );
    }

    public function test_employee_role_users_can_be_submitted_as_task_pocs(): void
    {
        $employeeRole = Role::query()->where('name', 'Employee')->where('guard_name', 'web')->firstOrFail();
        $employee = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'status' => 'active',
            'role_id' => $employeeRole->id,
        ]);

        $response = $this->actingAs($this->admin)->followingRedirects()->post(route('tasks.store'), [
            'title' => 'Employee can be a POC',
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'poc_ids' => [$employee->id],
            'task_type' => 'process',
            'priority' => 'medium',
            'original_deadline' => now()->addDays(2)->toDateString(),
            'estimated_hours' => 1,
            'expected_output' => 'A completed test deliverable.',
        ]);

        $response->assertOk()
            ->assertSee('Task created successfully.');
        $this->assertDatabaseHas('tasks', ['title' => 'Employee can be a POC']);
        $this->assertDatabaseHas('task_pocs', [
            'user_id' => $employee->id,
        ]);
    }

    public function test_tasks_export_downloads_csv(): void
    {
        Task::create([
            'task_code' => Task::generateCode(),
            'title' => 'Exportable Task',
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'task_type' => 'recurring',
            'priority' => 'medium',
            'status' => 'assigned',
            'original_deadline' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'estimated_hours' => 1,
            'expected_output' => 'A completed export test deliverable.',
            'created_by' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->get(route('tasks.export'));

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    }

    public function test_tasks_index_displays_reassign_action(): void
    {
        $task = Task::create([
            'task_code' => Task::generateCode(),
            'title' => 'Reassignable Task',
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'task_type' => 'process',
            'priority' => 'medium',
            'status' => 'unassigned',
            'original_deadline' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'estimated_hours' => 2,
            'expected_output' => 'Deliverable',
            'created_by' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->get(route('tasks.index'));

        $response->assertOk()
            ->assertSee('data-reassign-trigger', false)
            ->assertSee('Re-assign')
            ->assertSee('id="reassignTaskModal"', false)
            ->assertSee('id="reassignAssigneeToggle"', false)
            ->assertSee('id="reassignAssigneeMenu"', false)
            ->assertSee('id="reassignAssigneeOptions"', false)
            ->assertDontSee('id="reassignAssigneeSelect"', false);
    }

    public function test_authorized_user_can_reassign_task_and_records_history(): void
    {
        $user1 = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'status' => 'active',
        ]);

        $user2 = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'status' => 'active',
        ]);

        $task = Task::create([
            'task_code' => Task::generateCode(),
            'title' => 'Task to Reassign',
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'assigned_to' => $user1->id,
            'task_type' => 'process',
            'priority' => 'high',
            'status' => 'in_progress',
            'original_deadline' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'estimated_hours' => 3,
            'expected_output' => 'Output test',
            'created_by' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->post(route('tasks.reassign', $task), [
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'assigned_to' => $user2->id,
            'reason' => 'Workload rebalance',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame($user2->id, $task->fresh()->assigned_to);

        $this->assertDatabaseHas('task_assignments', [
            'task_id' => $task->id,
            'user_id' => $user1->id,
            'is_active' => false,
        ]);

        $this->assertDatabaseHas('task_assignments', [
            'task_id' => $task->id,
            'user_id' => $user2->id,
            'is_active' => true,
            'reason' => 'Workload rebalance',
        ]);

        // Check show page displays history
        $showResponse = $this->actingAs($this->admin)->get(route('tasks.show', $task));
        $showResponse->assertOk()
            ->assertSee('Assignment')
            ->assertSee($user1->name)
            ->assertSee($user2->name)
            ->assertSee('Workload rebalance')
            ->assertSee('Task summary')
            ->assertSee('Expected Output / Deliverable Criteria')
            ->assertSee('Description & Details')
            ->assertSee('No additional description provided.')
            ->assertSee('Task Created by')
            ->assertSee('Task due date')
            ->assertSee('Recent updates and history')
            ->assertSee('Planned start')
            ->assertSee('Deadline health')
            ->assertSee('Estimated effort')
            ->assertSee('Workflow progress')
            ->assertSee('<i class="bi bi-hourglass-split"></i>', false)
            ->assertSee('In Progress')
            ->assertSee('Previous')
            ->assertSee('Current');

        $showResponse->assertSeeInOrder([
            'tf-task-workflow__action-bar',
            'data-reassign-trigger',
            'Edit Task',
            'Task Workflow',
        ], false)
            ->assertSee('data-reassign-trigger', false)
            ->assertSee('class="tf-wf-action-btn tf-wf-action-btn--primary"', false)
            ->assertSee('class="tf-wf-action-btn tf-wf-action-btn--primary">', false);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createWorkflowTask(array $attributes = []): Task
    {
        return Task::create(array_merge([
            'task_code' => Task::generateCode(),
            'title' => 'Workflow task '.Task::generateCode(),
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'task_type' => 'process',
            'priority' => 'medium',
            'estimated_hours' => 2,
            'due_date' => now()->addDays(10)->toDateString(),
            'original_deadline' => now()->addDays(10)->toDateString(),
            'expected_output' => 'A completed workflow test deliverable.',
            'created_by' => $this->admin->id,
        ], $attributes));
    }

    private function assertKanbanOrder(TestResponse $response, Task ...$tasks): void
    {
        $boardHtml = $this->kanbanHtml($response);
        $positions = array_map(
            static fn (Task $task): int|false => strpos($boardHtml, 'data-task-id="'.$task->id.'"'),
            $tasks,
        );

        $this->assertNotContains(false, $positions, true);
        $sortedPositions = $positions;
        sort($sortedPositions);
        $this->assertSame($sortedPositions, $positions);
    }

    private function kanbanHtml(TestResponse $response): string
    {
        $html = $response->getContent();
        $boardStart = strpos($html, '<div id="taskKanbanView"');

        $this->assertNotFalse($boardStart);

        return substr($html, $boardStart);
    }
}
