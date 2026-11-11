<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\EmployeeDailyAccountability;
use App\Models\Subtask;
use App\Models\SubtaskDailyUpdate;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskStatusHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SubtaskDwrTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $employee;

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
        $this->employee = $this->createEmployee();
    }

    public function test_employee_dashboard_includes_assigned_subtasks_without_updates(): void
    {
        $task = $this->createTask($this->employee);
        $assignedSubtask = $this->createSubtask($task, $this->employee, 'Prepare daily report');
        $otherEmployee = $this->createEmployee();
        $otherSubtask = $this->createSubtask($task, $otherEmployee, 'Review another report');

        $this->actingAs($this->employee)
            ->get(route('dwr.index'))
            ->assertOk()
            ->assertSee($assignedSubtask->title)
            ->assertSee($task->title)
            ->assertSee('Subtask Status')
            ->assertSee('No update in this date range')
            ->assertSee('class="tf-dwr-subtask-table"', false)
            ->assertSee('Description')
            ->assertSee('Assignee')
            ->assertSee('Status')
            ->assertSee('Main Task')
            ->assertDontSee($otherSubtask->title);
    }

    public function test_subtask_status_overview_shows_progress_and_task_row_details(): void
    {
        $task = $this->createTask($this->employee);
        $subtask = $this->createSubtask($task, $this->employee, 'Complete the report');
        $subtask->update([
            'description' => 'Prepare and submit the weekly report.',
            'status' => 'completed',
            'due_date' => '2026-11-06',
            'progress_percentage' => 100,
        ]);

        $this->actingAs($this->employee)
            ->get(route('dwr.index'))
            ->assertOk()
            ->assertSee('Overall Progress')
            ->assertSee('1 of 1 subtasks completed')
            ->assertSee('tf-dwr-subtask-progress__chart', false)
            ->assertSee('tf-dwr-subtask-progress__chart-area', false)
            ->assertSee('tf-dwr-subtask-progress__chart-line', false)
            ->assertDontSee('tf-dwr-subtask-donut', false)
            ->assertSee('tf-dwr-subtask-status-card__progress', false)
            ->assertSee('Subtasks by Main Task', false)
            ->assertSee($task->title)
            ->assertSee('Complete the report')
            ->assertSee('Prepare and submit the weekly report.')
            ->assertSee($this->employee->name)
            ->assertSee('Created Date')
            ->assertSee($subtask->created_at->format('M j, Y'))
            ->assertSee('tf-dwr-subtask-status-card--completed', false);
    }

    public function test_dashboard_orders_critical_main_task_subtasks_first(): void
    {
        $criticalTask = $this->createTask($this->employee);
        $criticalTask->update(['priority' => 'critical']);
        $criticalSubtask = $this->createSubtask($criticalTask, $this->employee, 'Resolve critical blocker');

        $highPriorityTask = $this->createTask($this->employee);
        $highPriorityTask->update(['priority' => 'high']);
        $highPrioritySubtask = $this->createSubtask($highPriorityTask, $this->employee, 'Prepare high priority report');

        $response = $this->actingAs($this->employee)
            ->get(route('dwr.index'))
            ->assertOk()
            ->assertSee('Total Main Tasks')
            ->assertSee('Critical priority')
            ->assertSee($criticalTask->title)
            ->assertSee($criticalSubtask->title)
            ->assertDontSee('<table', false);

        $response->assertSeeInOrder([$criticalSubtask->title, $highPrioritySubtask->title]);
    }

    public function test_priority_breakdown_counts_main_tasks_without_subtasks(): void
    {
        $initialTaskCount = Task::query()->count();
        $initialPriorityCounts = Task::query()
            ->selectRaw('priority, COUNT(*) as total')
            ->groupBy('priority')
            ->pluck('total', 'priority');

        $criticalTask = $this->createTask($this->employee);
        $criticalTask->update(['priority' => 'critical']);

        $mediumTask = $this->createTask($this->employee);
        $mediumTask->update(['priority' => 'medium']);
        $this->createSubtask($mediumTask, $this->employee, 'Medium priority subtask');

        $this->actingAs($this->admin)
            ->get(route('dwr.index'))
            ->assertOk()
            ->assertSee(($initialTaskCount + 2).' - Tasks')
            ->assertViewHas('priorityTaskCountTotal', $initialTaskCount + 2)
            ->assertViewHas('priorityBreakdown', function ($priorityBreakdown) use ($initialPriorityCounts): bool {
                $this->assertSame((int) ($initialPriorityCounts['critical'] ?? 0) + 1, $priorityBreakdown['critical']);
                $this->assertSame((int) ($initialPriorityCounts['medium'] ?? 0) + 1, $priorityBreakdown['medium']);

                return true;
            });
    }

    public function test_main_task_status_card_counts_visible_tasks_by_workflow_status(): void
    {
        $initialTaskStatusCounts = Task::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');
        $initialTaskCount = Task::query()->count();

        foreach (Task::STATUSES as $status) {
            $task = $this->createTask($this->employee, 'DWR-STATUS-'.strtoupper($status));
            $task->update(['status' => $status]);
        }

        $response = $this->actingAs($this->admin)
            ->get(route('dwr.index'))
            ->assertOk()
            ->assertViewHas('mainTaskStatusCountTotal', $initialTaskCount + count(Task::STATUSES))
            ->assertViewHas('mainTaskStatusBreakdown', function ($statusBreakdown) use ($initialTaskStatusCounts): bool {
                foreach (Task::STATUSES as $status) {
                    $this->assertSame(
                        (int) ($initialTaskStatusCounts[$status] ?? 0) + 1,
                        $statusBreakdown[$status],
                        "Unexpected count for {$status} main tasks.",
                    );
                }

                return true;
            });

        $content = $response->getContent();
        $priorityPosition = strpos($content, '<h2>Main Task Priority</h2>');
        $taskStatusPosition = strpos($content, '<h2>Main Task Status</h2>');
        $subtaskStatusPosition = strpos($content, 'id="dwrSubtaskStatusTitle">Subtask Status</h2>');
        $this->assertNotFalse($priorityPosition);
        $this->assertNotFalse($taskStatusPosition);
        $this->assertNotFalse($subtaskStatusPosition);
        $this->assertLessThan($taskStatusPosition, $priorityPosition);
        $this->assertLessThan($subtaskStatusPosition, $taskStatusPosition);
        $statusLabelsPosition = strpos($content, '<h2>Main Task Status</h2>');
        $statusSection = substr($content, $statusLabelsPosition, $subtaskStatusPosition - $statusLabelsPosition);
        $this->assertStringContainsString('tf-dwr-task-status-rows', $statusSection);
        $this->assertStringContainsString('tf-dwr-task-status-row__plot', $statusSection);
        $this->assertStringNotContainsString('dwrMainTaskStatusChart', $content);
        $this->assertStringNotContainsString('dwrMainTaskStatusValueLabels', $content);

        $expectedStatuses = [
            'assigned' => 'Assigned',
            'in_progress' => 'In-progress',
            'on_hold' => 'On-hold',
            'submitted_for_review' => 'Review',
            'rework' => 'Rework',
            'completed' => 'Completed',
            'cancelled' => 'Canceled',
            'unassigned' => 'Unassigned',
            'notified' => 'Notified',
            'missed' => 'Missed',
        ];
        $statusCounts = array_map(
            fn (string $status): int => (int) ($initialTaskStatusCounts[$status] ?? 0) + 1,
            Task::STATUSES,
        );
        $expectedMaxCount = max($statusCounts);
        $lastLabelPosition = -1;

        foreach ($expectedStatuses as $status => $label) {
            $labelPosition = strpos($statusSection, '<strong>'.$label.'</strong>');
            $this->assertNotFalse($labelPosition, "Missing {$label} task status row.");
            $this->assertGreaterThan($lastLabelPosition, $labelPosition, "{$label} is out of order.");
            $lastLabelPosition = $labelPosition;

            $statusCount = (int) ($initialTaskStatusCounts[$status] ?? 0) + 1;
            $statusPercent = round(($statusCount / $expectedMaxCount) * 86);
            $this->assertStringContainsString(
                'aria-label="'.$label.' main tasks"',
                $statusSection,
            );
            $this->assertStringContainsString(
                'aria-valuenow="'.$statusCount.'"',
                $statusSection,
            );
            $this->assertStringContainsString(
                'aria-valuemax="'.$expectedMaxCount.'"',
                $statusSection,
            );
            $minimumWidth = $statusCount > 0 ? '14px' : '0';
            $this->assertStringContainsString(
                'style="width: '.$statusPercent.'%; min-width: '.$minimumWidth.'"',
                $statusSection,
            );
            $this->assertStringContainsString(
                'style="left: max(20px, calc('.$statusPercent.'% + 6px))"',
                $statusSection,
            );
        }
    }

    public function test_dashboard_metric_cards_show_filtered_task_and_subtask_totals(): void
    {
        $completedCriticalTask = $this->createTask($this->employee);
        $completedCriticalTask->update(['priority' => 'critical', 'status' => 'completed']);
        $completedCriticalSubtask = $this->createSubtask($completedCriticalTask, $this->employee, 'Completed critical work');
        $completedCriticalSubtask->update(['status' => 'completed']);
        $this->createSubtask($completedCriticalTask, $this->employee, 'Remaining critical work');

        $activeTask = $this->createTask($this->employee);
        $activeTask->update(['priority' => 'high', 'status' => 'in_progress']);
        $completedSubtask = $this->createSubtask($activeTask, $this->employee, 'Completed regular work');
        $completedSubtask->update(['status' => 'completed']);

        $response = $this->actingAs($this->employee)
            ->get(route('dwr.index'))
            ->assertOk()
            ->assertSee('Total Main Tasks')
            ->assertSee('Critical Subtasks')
            ->assertSee('Completed Tasks')
            ->assertSee('Completed Subtasks')
            ->assertSee('tf-dwr-priority-percent', false)
            ->assertSee('tf-dwr-priority-icon--critical', false)
            ->assertSee('tf-dwr-priority-icon--medium', false)
            ->assertSee('100%', false);

        $response->assertViewHas('metrics', fn (array $metrics): bool => $metrics['total_main_tasks'] === 2
            && $metrics['critical_subtasks'] === 2
            && $metrics['completed_tasks'] === 1
            && $metrics['completed_subtasks'] === 2
            && $metrics['total_subtasks'] === 3);
        $response->assertSee('Subtasks by Main Task')
            ->assertViewHas('mainTaskSummaries', function ($summaries) use ($completedCriticalTask, $activeTask): bool {
                $summaries = $summaries->keyBy('task_id');

                $this->assertCount(2, $summaries);
                $this->assertSame(2, (int) $summaries[$completedCriticalTask->id]->total_subtasks);
                $this->assertSame(1, (int) $summaries[$completedCriticalTask->id]->status_completed);
                $this->assertSame(1, (int) $summaries[$completedCriticalTask->id]->status_assigned);
                $this->assertSame(1, (int) $summaries[$activeTask->id]->total_subtasks);
                $this->assertSame(1, (int) $summaries[$activeTask->id]->status_completed);

                return true;
            });

        $response->assertSee('class="tf-dwr-subtask-status-group__count"', false)
            ->assertSee('Subtasks')
            ->assertSee('class="tf-dwr-subtask-table__row tf-dwr-subtask-row--completed"', false);
    }

    public function test_dashboard_status_breakdown_shows_four_grouped_statuses(): void
    {
        $task = $this->createTask($this->employee);
        $task->update(['priority' => 'critical']);
        foreach (['completed', 'on_hold', 'in_progress', 'todo', 'assigned', 'in_review'] as $status) {
            $subtask = $this->createSubtask($task, $this->employee, "Subtask {$status}");
            $subtask->update(['status' => $status]);
        }

        $response = $this->actingAs($this->employee)
            ->get(route('dwr.index'))
            ->assertOk();
        $content = $response->getContent();
        $statusHeadingPosition = strpos($content, 'id="dwrSubtaskStatusTitle">Subtask Status</h2>');
        $nextSectionPosition = strpos($content, 'class="tf-dwr-subtask-status-groups"');

        $this->assertNotFalse($statusHeadingPosition);
        $this->assertNotFalse($nextSectionPosition);
        $this->assertLessThan(strpos($content, 'class="tf-dwr-subtask-overview"'), strpos($content, 'Subtasks by Main Task'));

        $statusSection = substr($content, $statusHeadingPosition, $nextSectionPosition - $statusHeadingPosition);
        foreach (['Completed', 'On Hold', 'In Progress', 'Pending'] as $label) {
            $this->assertStringContainsString('>'.$label.'</span>', $statusSection);
        }
        foreach (['Assigned', 'In Review', 'To Do'] as $label) {
            $this->assertStringNotContainsString('>'.$label.'</span>', $statusSection);
        }
        preg_match_all(
            '/tf-dwr-subtask-status-card--(completed|on_hold|in_progress|pending).*?<div class="tf-dwr-subtask-status-card__content">\s*<span>.*?<\/span>.*?<span class="tf-dwr-subtask-status-card__percent">(\d+)%.*?<strong class="tf-dwr-subtask-status-card__count">(\d+)<\/strong>/s',
            $statusSection,
            $statusCounts,
            PREG_SET_ORDER,
        );
        $this->assertSame([
            ['tf-dwr-subtask-status-card--completed', '1', '17'],
            ['tf-dwr-subtask-status-card--in_progress', '1', '17'],
            ['tf-dwr-subtask-status-card--on_hold', '1', '17'],
            ['tf-dwr-subtask-status-card--pending', '3', '50'],
        ], array_map(
            fn (array $match): array => ['tf-dwr-subtask-status-card--'.$match[1], $match[3], $match[2]],
            $statusCounts,
        ));
        $response->assertSee('tf-dwr-subtask-status-card--completed', false)
            ->assertSee('tf-dwr-subtask-status-card--in_progress', false)
            ->assertSee('tf-dwr-subtask-status-card--on_hold', false)
            ->assertSee('tf-dwr-subtask-status-card--pending', false)
            ->assertSee('tf-dwr-subtask-status-card__progress', false)
            ->assertSee('class="tf-dwr-subtask-table"', false)
            ->assertSee('class="tf-dwr-subtask-status tf-dwr-subtask-status--completed"', false);
    }

    public function test_main_task_summary_lists_multiple_subtasks_in_status_priority_order(): void
    {
        $task = $this->createTask($this->employee);
        $task->update([
            'priority' => 'critical',
            'title' => 'Develop REST API for User Management',
        ]);

        foreach ([
            'Completed work' => 'completed',
            'Paused work' => 'on_hold',
            'Active work' => 'in_progress',
            'Todo work' => 'todo',
            'Assigned work' => 'assigned',
            'Review work' => 'in_review',
        ] as $title => $status) {
            $subtask = $this->createSubtask($task, $this->employee, $title);
            $subtask->update(['status' => $status]);
        }

        $response = $this->actingAs($this->employee)
            ->get(route('dwr.index'))
            ->assertOk()
            ->assertSee('tf-dwr-subtask-table', false)
            ->assertSee('tf-dwr-subtask-row--completed', false)
            ->assertSee('tf-dwr-subtask-row--on_hold', false)
            ->assertSee('tf-dwr-subtask-row--in_progress', false)
            ->assertSee('tf-dwr-subtask-row--todo', false)
            ->assertSee('tf-dwr-subtask-row--assigned', false)
            ->assertSee('tf-dwr-subtask-row--in_review', false)
            ->assertSee('Completed work')
            ->assertSee('Paused work')
            ->assertSee('Active work')
            ->assertSee('Todo work')
            ->assertSee('Assigned work')
            ->assertSee('Review work');

        $content = $response->getContent();
        $summaryStart = strpos($content, 'class="tf-dwr-subtask-status-groups"');
        $summaryEnd = strpos($content, '</section>', $summaryStart);
        $this->assertNotFalse($summaryStart);
        $this->assertNotFalse($summaryEnd);
        $summarySection = substr($content, $summaryStart, $summaryEnd - $summaryStart);
        $this->assertStringContainsString('<th scope="col">Subtask</th>', $summarySection);
        $this->assertStringContainsString('<th scope="col">Description</th>', $summarySection);
        $this->assertStringContainsString('<th scope="col">Assignee</th>', $summarySection);
        $this->assertStringContainsString('<th scope="col">Status</th>', $summarySection);
        $this->assertStringContainsString('<th scope="col">Main Task</th>', $summarySection);
        $this->assertStringContainsString('tf-dwr-subtask-table__assignee', $summarySection);
        $this->assertStringContainsString('class="tf-dwr-subtask-table__main-task-link"', $summarySection);
        $this->assertSame(6, substr_count($summarySection, 'class="tf-dwr-subtask-table__row tf-dwr-subtask-row--'));
        $this->assertStringContainsString('class="tf-dwr-subtask-status-group__count"', $summarySection);
        $this->assertStringNotContainsString('tf-dwr-subtask-status-group__count visually-hidden', $summarySection);
        foreach (['Completed', 'On Hold', 'In Progress', 'Pending', 'Assigned', 'In Review'] as $label) {
            $this->assertStringContainsString($label, $summarySection);
        }
        $this->assertStringContainsString('tf-dwr-subtask-table__folder', $summarySection);
        $this->assertStringContainsString('(Main task)', $summarySection);
        $this->assertStringContainsString('href="'.route('tasks.show', ['task' => $task->id]).'"', $summarySection);
        $this->assertStringContainsString('title="Develop REST API for User Management">Develop REST API for...', $summarySection);

        $subtaskPositions = array_map(
            fn (string $title): int|false => strpos($summarySection, $title),
            [
                'Active work',
                'Assigned work',
                'Completed work',
                'Paused work',
                'Review work',
                'Todo work',
            ],
        );
        $this->assertNotContains(false, $subtaskPositions, true);
        $orderedPositions = $subtaskPositions;
        sort($orderedPositions);
        $this->assertSame($orderedPositions, $subtaskPositions);
    }

    public function test_main_task_summary_date_tracks_custom_dashboard_filter(): void
    {
        $this->actingAs($this->employee)
            ->get(route('dwr.index', [
                'date_range' => 'custom',
                'from_date' => '2026-10-12',
                'to_date' => '2026-10-12',
            ]))
            ->assertOk()
            ->assertSee('October 12, 2026');
    }

    public function test_dashboard_filters_are_available_in_the_header_card(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('dwr.index'))
            ->assertOk()
            ->assertViewIs('dwr_dashboard.index')
            ->assertSee('class="tf-dwr-header-filters"', false)
            ->assertDontSee('id="dwrFilters"', false);
        $content = $response->getContent();
        $filterStart = strpos($content, 'id="dwrHeaderFilters"');
        $createModalStart = strpos($content, 'id="dwrCreateSubtask"');

        $this->assertNotFalse($filterStart);
        $this->assertNotFalse($createModalStart);

        $filterMarkup = substr($content, $filterStart, $createModalStart - $filterStart);
        $this->assertStringContainsString('name="date_range"', $filterMarkup);
        $this->assertStringContainsString('name="employee_id"', $filterMarkup);
        $this->assertStringContainsString('type="date"', $filterMarkup);
        $this->assertStringContainsString('name="from_date"', $filterMarkup);
        $this->assertStringContainsString('name="to_date"', $filterMarkup);
        $this->assertStringContainsString('Apply', $filterMarkup);
        $this->assertStringNotContainsString('name="priority"', $filterMarkup);
        $this->assertStringNotContainsString('name="status"', $filterMarkup);
    }

    public function test_employee_filter_limits_dashboard_task_details_to_the_selected_employee(): void
    {
        $selectedTask = $this->createTask($this->employee);
        $selectedTask->update(['priority' => 'critical']);
        $selectedSubtask = $this->createSubtask($selectedTask, $this->employee, 'Selected employee task detail');

        $otherEmployee = $this->createEmployee();
        $otherTask = $this->createTask($otherEmployee);
        $otherTask->update(['priority' => 'high']);
        $otherSubtask = $this->createSubtask($otherTask, $otherEmployee, 'Other employee task detail');

        $response = $this->actingAs($this->admin)
            ->get(route('dwr.index', ['employee_id' => $this->employee->id]))
            ->assertOk();

        $response->assertViewHas('subtasks', function ($subtasks) use ($selectedSubtask, $otherSubtask): bool {
            $this->assertSame(1, $subtasks->total());
            $this->assertSame($selectedSubtask->id, $subtasks->getCollection()->first()->id);
            $this->assertFalse($subtasks->getCollection()->contains('id', $otherSubtask->id));

            return true;
        });
        $response->assertViewHas('mainTaskSummaries', function ($summaries) use ($selectedTask, $otherTask): bool {
            $this->assertCount(1, $summaries);
            $this->assertSame($selectedTask->id, (int) $summaries->first()->task_id);
            $this->assertFalse($summaries->contains('task_id', $otherTask->id));

            return true;
        });
        $response->assertViewHas('priorityTaskCountTotal', 1)
            ->assertViewHas('priorityBreakdown', function ($priorityBreakdown): bool {
                $this->assertSame(1, $priorityBreakdown['critical']);
                $this->assertSame(0, $priorityBreakdown['high']);

                return true;
            });

        $this->actingAs($this->admin)
            ->get(route('dwr.index'))
            ->assertViewHas('metrics', function (array $metrics): bool {
                $this->assertSame(2, $metrics['total_main_tasks']);
                $this->assertSame(2, $metrics['total_subtasks']);

                return true;
            });
    }

    public function test_assigned_employee_can_submit_a_daily_update_and_record_activity(): void
    {
        $task = $this->createTask($this->employee);
        $subtask = $this->createSubtask($task, $this->employee, 'Implement dashboard');
        $workDate = today()->toDateString();

        $this->actingAs($this->employee)
            ->post(route('dwr.subtasks.daily-updates.store', [$task, $subtask]), [
                'work_date' => $workDate,
                'work_description' => 'Implemented the dashboard table.',
                'work_completed' => 'Added the priority column.',
                'time_spent_minutes' => 45,
            ])
            ->assertRedirect(route('dwr.index', [
                'date_range' => 'custom',
                'from_date' => $workDate,
                'to_date' => $workDate,
            ]));

        $this->assertDatabaseHas('subtask_daily_updates', [
            'task_id' => $task->id,
            'subtask_id' => $subtask->id,
            'employee_id' => $this->employee->id,
            'work_description' => 'Implemented the dashboard table.',
            'time_spent_minutes' => 45,
        ]);
        $this->assertSame(
            1,
            SubtaskDailyUpdate::query()
                ->where('subtask_id', $subtask->id)
                ->whereDate('work_date', $workDate)
                ->count(),
        );
        $this->assertTrue(TaskStatusHistory::query()
            ->where('task_id', $task->id)
            ->where('subtask_id', $subtask->id)
            ->where('changed_by', $this->employee->id)
            ->where('event_type', 'subtask_daily_update')
            ->whereDate('activity_date', $workDate)
            ->exists());
        $this->assertSame(45, (int) $subtask->fresh()->actual_elapsed_minutes);
        $this->assertSame('0.75', (string) $subtask->fresh()->actual_hours);
        $this->assertTrue(EmployeeDailyAccountability::query()
            ->where('employee_id', $this->employee->id)
            ->whereDate('activity_date', $workDate)
            ->where('activity_status', 'recorded')
            ->where('activity_count', 1)
            ->where('warning_status', false)
            ->exists());
    }

    public function test_another_user_cannot_submit_an_update_for_an_assigned_subtask(): void
    {
        $task = $this->createTask($this->employee);
        $subtask = $this->createSubtask($task, $this->employee, 'Private assignment');

        $this->actingAs($this->admin)
            ->post(route('dwr.subtasks.daily-updates.store', [$task, $subtask]), [
                'work_date' => today()->toDateString(),
                'work_description' => 'Unauthorized update.',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('subtask_daily_updates', 0);
    }

    public function test_employee_subtask_creation_ignores_manager_only_fields(): void
    {
        $task = $this->createTask($this->employee);

        $this->actingAs($this->employee)
            ->postJson(route('subtasks.store', $task), [
                'title' => 'Employee-owned subtask',
                'assigned_to' => $this->admin->id,
                'priority' => 'critical',
                'status' => 'completed',
                'progress_percentage' => 100,
                'start_date' => today()->toDateString(),
                'estimated_hours' => 8,
            ])
            ->assertCreated()
            ->assertJsonPath('subtask.assigned_to.id', $this->employee->id)
            ->assertJsonPath('subtask.status', 'assigned')
            ->assertJsonPath('subtask.priority', 'medium')
            ->assertJsonPath('subtask.progress_percentage', 0);
    }

    public function test_cutoff_warning_is_stored_idempotently_and_cleared_by_later_activity(): void
    {
        $workDate = today()->toDateString();
        $missingEmployee = $this->createEmployee();
        $task = $this->createTask($this->employee);

        $this->travelTo(today()->setTime(23, 59, 1));
        TaskStatusHistory::query()->create([
            'task_id' => $task->id,
            'changed_by' => $this->employee->id,
            'old_status' => 'assigned',
            'new_status' => 'in_progress',
            'event_type' => 'work_update',
            'activity_date' => $workDate,
            'remarks' => 'Recorded work activity.',
        ]);

        $this->artisan('dwr:generate-missing-activity', ['--date' => $workDate])->assertExitCode(0);
        $this->artisan('dwr:generate-missing-activity', ['--date' => $workDate])->assertExitCode(0);

        $this->assertTrue(EmployeeDailyAccountability::query()
            ->where('employee_id', $this->employee->id)
            ->whereDate('activity_date', $workDate)
            ->where('activity_status', 'recorded')
            ->where('warning_status', false)
            ->exists());
        $this->assertTrue(EmployeeDailyAccountability::query()
            ->where('employee_id', $missingEmployee->id)
            ->whereDate('activity_date', $workDate)
            ->where('activity_status', 'missed')
            ->where('warning_status', true)
            ->exists());
        $this->assertSame(
            1,
            EmployeeDailyAccountability::query()
                ->where('employee_id', $missingEmployee->id)
                ->whereDate('activity_date', $workDate)
                ->count(),
        );

        TaskStatusHistory::query()->create([
            'task_id' => $task->id,
            'changed_by' => $missingEmployee->id,
            'old_status' => 'assigned',
            'new_status' => 'in_progress',
            'event_type' => 'work_update',
            'activity_date' => $workDate,
            'remarks' => 'Late work activity.',
        ]);

        $this->assertTrue(EmployeeDailyAccountability::query()
            ->where('employee_id', $missingEmployee->id)
            ->whereDate('activity_date', $workDate)
            ->where('activity_status', 'recorded')
            ->where('warning_status', false)
            ->exists());
    }

    public function test_cutoff_command_rejects_today_before_1159_pm(): void
    {
        $workDate = today()->toDateString();
        $this->travelTo(today()->setTime(23, 58, 59));

        $this->artisan('dwr:generate-missing-activity', ['--date' => $workDate])->assertExitCode(1);

        $this->assertSame(
            0,
            EmployeeDailyAccountability::query()
                ->where('employee_id', $this->employee->id)
                ->whereDate('activity_date', $workDate)
                ->count(),
        );
    }

    private function createEmployee(): User
    {
        $employee = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'status' => 'active',
        ]);
        $employee->assignRole(Role::query()->where('name', 'Employee')->firstOrFail());

        return $employee;
    }

    private function createTask(User $employee, ?string $taskCode = null): Task
    {
        $task = Task::factory()->create([
            ...($taskCode === null ? [] : ['task_code' => $taskCode]),
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'assigned_to' => $employee->id,
            'status' => 'assigned',
            'start_date' => today()->toDateString(),
            'due_date' => today()->addDays(3)->toDateString(),
            'original_deadline' => today()->addDays(3)->toDateString(),
            'created_by' => $this->admin->id,
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

    private function createSubtask(Task $task, User $employee, string $title): Subtask
    {
        return $task->subtasks()->create([
            'created_by' => $this->admin->id,
            'assigned_to' => $employee->id,
            'title' => $title,
            'status' => 'assigned',
        ]);
    }
}
