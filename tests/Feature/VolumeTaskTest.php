<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\TaskVolumeUpdate;
use App\Models\User;
use App\Services\VolumeTaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class VolumeTaskTest extends TestCase
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

    public function test_volume_task_creation_persists_the_configured_start_and_deadline_dates(): void
    {
        $this->travelTo('2026-11-09 10:00:00');
        $poc = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'status' => 'active',
        ]);

        $this->actingAs($this->admin)->post(route('tasks.store'), [
            'title' => 'Student verification',
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'poc_ids' => [$poc->id],
            'task_type' => 'volume',
            'priority' => 'high',
            'volume_total' => 10,
            'volume_start_date' => '2026-11-09',
            'volume_deadline' => '2026-11-13',
            'expected_output' => 'All student documents are verified.',
        ])->assertRedirect(route('tasks.index'));

        $task = Task::query()->where('title', 'Student verification')->firstOrFail();
        $this->assertSame('volume', $task->task_type);
        $this->assertSame('2026-11-09', $task->start_date?->toDateString());
        $this->assertDatabaseHas('task_volumes', [
            'task_id' => $task->id,
            'total_volume' => 10,
        ]);
        $volume = $task->volume()->firstOrFail();
        $this->assertSame('2026-11-09', $volume->start_date->toDateString());
        $this->assertSame('2026-11-13', $volume->deadline_date->toDateString());
        $this->assertDatabaseHas('task_status_histories', [
            'task_id' => $task->id,
            'event_type' => 'volume_configuration_created',
        ]);
    }

    public function test_volume_task_deadline_cannot_precede_its_start_date(): void
    {
        $this->travelTo('2026-11-09 10:00:00');
        $poc = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'status' => 'active',
        ]);

        $this->actingAs($this->admin)->post(route('tasks.store'), [
            'title' => 'Invalid volume period',
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'poc_ids' => [$poc->id],
            'task_type' => 'volume',
            'priority' => 'high',
            'volume_total' => 10,
            'volume_start_date' => '2026-11-13',
            'volume_deadline' => '2026-11-12',
            'expected_output' => 'All items are completed.',
        ])->assertSessionHasErrors('volume_deadline');

        $this->assertDatabaseMissing('tasks', ['title' => 'Invalid volume period']);
    }

    public function test_subtask_creation_aggregates_counts_without_mixing_parent_tasks(): void
    {
        $this->travelTo('2026-11-10 10:00:00');
        $firstTask = $this->volumeTask(10, '2026-11-09', '2026-11-13', 'VOL-TEST-FIRST');
        $secondTask = $this->volumeTask(8, '2026-11-09', '2026-11-13', 'VOL-TEST-SECOND');
        $firstEmployee = $this->employee();
        $secondEmployee = $this->employee();

        $firstResponse = $this->createVolumeSubtask($firstTask, 'Application verification', '2026-11-10', $firstEmployee);
        $firstResponse->assertCreated()
            ->assertJsonPath('subtask.volume_update.work_date', '2026-11-10');
        $this->createVolumeSubtask($firstTask, 'Document review', '2026-11-10', $secondEmployee)->assertCreated();
        $this->createVolumeSubtask($secondTask, 'Independent task work', '2026-11-10', $firstEmployee)->assertCreated();

        $this->assertDatabaseCount('task_volume_updates', 3);
        $this->assertDatabaseHas('task_volume_updates', [
            'task_id' => $firstTask->id,
            'user_id' => $firstEmployee->id,
            'completed_count' => 1,
        ]);
        $this->assertDatabaseHas('task_volume_updates', [
            'task_id' => $firstTask->id,
            'user_id' => $secondEmployee->id,
            'completed_count' => 1,
        ]);

        $this->actingAs($this->admin)->get(route('tasks.show', $firstTask))
            ->assertOk()
            ->assertViewHas('volumeSummary', function (array $summary): bool {
                $today = $summary['rows']->first(
                    fn (array $row): bool => $row['date']->toDateString() === '2026-11-10',
                );

                $this->assertSame(2, $today['actual']);
                $this->assertSame(4, $today['cumulative_target']);
                $this->assertSame(2, $today['cumulative_actual']);
                $this->assertSame('Behind', $today['status']);
                $this->assertSame(2, $summary['today_actual_count']);
                $this->assertSame(2, $summary['completed']);
                $this->assertSame(8, $summary['remaining']);
                $this->assertSame(20, $summary['percentage']);

                return true;
            });

        $this->actingAs($this->admin)->get(route('tasks.show', $secondTask))
            ->assertOk()
            ->assertViewHas('volumeSummary', function (array $summary): bool {
                $this->assertSame(1, $summary['completed']);
                $this->assertSame(12, $summary['percentage']);

                return true;
            });
    }

    public function test_editing_and_deleting_a_subtask_replaces_and_removes_its_contribution(): void
    {
        $this->travelTo('2026-11-11 10:00:00');
        $task = $this->volumeTask(10, '2026-11-09', '2026-11-13', 'VOL-TEST-LIFECYCLE');
        $subtask = $this->createVolumeSubtask($task, 'First subtask', '2026-11-10')->assertCreated();
        $subtaskId = (int) $subtask->json('subtask.id');
        $otherSubtask = $this->createVolumeSubtask($task, 'Second subtask', '2026-11-10')->assertCreated();
        $employee = $this->employee();

        $this->actingAs($this->admin)
            ->patchJson(route('subtasks.update', [$task, $subtaskId]), [
                'work_date' => '2026-11-11',
                'assigned_to' => $employee->id,
            ])
            ->assertOk()
            ->assertJsonPath('subtask.volume_update.work_date', '2026-11-11');

        $this->assertDatabaseCount('task_volume_updates', 2);
        $this->assertDatabaseHas('task_volume_updates', [
            'task_id' => $task->id,
            'subtask_id' => $subtaskId,
            'user_id' => $employee->id,
            'completed_count' => 1,
        ]);
        $this->assertDatabaseHas('task_volume_updates', [
            'task_id' => $task->id,
            'subtask_id' => $otherSubtask->json('subtask.id'),
            'completed_count' => 1,
        ]);
        $this->assertSame(
            '2026-11-11',
            TaskVolumeUpdate::query()->where('subtask_id', $subtaskId)->firstOrFail()->update_date->toDateString(),
        );
        $this->assertSame(
            '2026-11-10',
            TaskVolumeUpdate::query()->where('subtask_id', $otherSubtask->json('subtask.id'))->firstOrFail()->update_date->toDateString(),
        );
        $this->assertSame(20, $task->fresh()->progress_percentage);

        $this->actingAs($this->admin)->deleteJson(route('subtasks.destroy', [$task, $subtaskId]))->assertOk();
        $this->assertDatabaseCount('task_volume_updates', 1);
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'progress_percentage' => 10]);
        $this->assertDatabaseMissing('task_volume_updates', ['subtask_id' => $subtaskId]);
    }

    public function test_actual_output_is_the_number_of_subtasks_for_that_date(): void
    {
        $this->travelTo('2026-11-10 10:00:00');
        $task = $this->volumeTask(3, '2026-11-09', '2026-11-13', 'VOL-TEST-OVER-TARGET');
        $this->createVolumeSubtask($task, 'First output', '2026-11-10')->assertCreated();
        $this->createVolumeSubtask($task, 'Second output', '2026-11-10')->assertCreated();

        $this->actingAs($this->admin)->get(route('tasks.show', $task))
            ->assertOk()
            ->assertViewHas('volumeSummary', function (array $summary): bool {
                $today = $summary['rows']->first(
                    fn (array $row): bool => $row['date']->toDateString() === '2026-11-10',
                );

                $this->assertSame(2, $today['actual']);
                $this->assertSame(2, $summary['completed']);
                $this->assertSame(1, $summary['remaining']);
                $this->assertSame(66, $summary['percentage']);

                return true;
            });

        $this->assertSame(66, $task->fresh()->progress_percentage);
    }

    public function test_editing_a_subtask_moves_its_single_volume_contribution_to_the_new_date(): void
    {
        $this->travelTo('2026-11-11 10:00:00');
        $task = $this->volumeTask(10, '2026-11-09', '2026-11-13', 'VOL-TEST-CLEAR-COUNT');
        $subtaskResponse = $this->createVolumeSubtask($task, 'Subtask to move', '2026-11-10')->assertCreated();
        $subtaskId = (int) $subtaskResponse->json('subtask.id');

        $this->actingAs($this->admin)
            ->patchJson(route('subtasks.update', [$task, $subtaskId]), [
                'work_date' => '2026-11-11',
            ])
            ->assertOk()
            ->assertJsonPath('subtask.volume_update.work_date', '2026-11-11');

        $this->assertDatabaseCount('subtasks', 1);
        $this->assertDatabaseCount('task_volume_updates', 1);
        $this->assertSame(10, $task->fresh()->progress_percentage);
    }

    public function test_each_volume_subtask_counts_as_one_actual_output(): void
    {
        $this->travelTo('2026-11-10 10:00:00');
        $task = $this->volumeTask(10, '2026-11-09', '2026-11-13', 'VOL-TEST-NO-COUNT');
        $response = $this->actingAs($this->admin)->postJson(route('subtasks.store', $task), [
            'title' => 'Completed subtask',
        ])->assertCreated();
        $subtaskId = (int) $response->json('subtask.id');

        $this->assertDatabaseHas('task_volume_updates', [
            'task_id' => $task->id,
            'subtask_id' => $subtaskId,
            'completed_count' => 1,
        ]);
        $this->actingAs($this->admin)
            ->patchJson(route('subtasks.update', [$task, $subtaskId]), ['status' => 'completed'])
            ->assertOk();

        $this->assertDatabaseCount('task_volume_updates', 1);
        $this->assertSame(10, $task->fresh()->progress_percentage);
        $this->actingAs($this->admin)->get(route('tasks.show', $task))
            ->assertOk()
            ->assertViewHas('volumeSummary', fn (array $summary): bool => $summary['completed'] === 1);
    }

    public function test_daily_schedule_warns_when_subtask_count_is_below_the_daily_target(): void
    {
        $this->travelTo('2026-11-10 10:00:00');
        $task = $this->volumeTask(10, '2026-11-09', '2026-11-13', 'VOL-TEST-ZERO');

        $response = $this->createVolumeSubtask($task, 'Output subtask', '2026-11-10')->assertCreated();
        $subtaskId = (int) $response->json('subtask.id');
        $this->assertDatabaseHas('task_volume_updates', ['task_id' => $task->id, 'subtask_id' => $subtaskId]);

        $this->actingAs($this->admin)->get(route('tasks.show', $task))
            ->assertOk()
            ->assertViewHas('volumeSummary', function (array $summary): bool {
                $today = $summary['rows']->first(
                    fn (array $row): bool => $row['date']->toDateString() === '2026-11-10',
                );

                $this->assertSame(1, $today['actual']);
                $this->assertTrue($today['has_update']);
                $this->assertSame('Behind', $today['status']);
                $this->assertSame(1, $summary['mismatch_count']);
                $this->assertSame('Missed Daily Update', $summary['rows']->first()['daily_update_status']);

                return true;
            });

        $this->actingAs($this->admin)->get(route('tasks.show', $task))
            ->assertOk()
            ->assertSee('Cumulative output is 1. Additional output is needed to complete the volume.');
    }

    public function test_cumulative_actual_catches_up_without_clearing_a_historical_missed_update(): void
    {
        $this->travelTo('2026-11-12 10:00:00');
        $task = $this->volumeTask(10, '2026-11-11', '2026-11-17', 'VOL-CUMULATIVE-CATCHUP');
        foreach (range(1, 4) as $number) {
            $this->createVolumeSubtask($task, "Catch-up output {$number}", '2026-11-12')->assertCreated();
        }

        $this->actingAs($this->admin)->get(route('tasks.show', $task))
            ->assertOk()
            ->assertViewHas('volumeSummary', function (array $summary): bool {
                $firstDay = $summary['rows']->first();
                $secondDay = $summary['rows']->get(1);

                $this->assertSame(2, $summary['minimum_daily_count']);
                $this->assertSame(4, $secondDay['cumulative_target']);
                $this->assertSame(4, $secondDay['cumulative_actual']);
                $this->assertSame('Caught Up', $secondDay['status']);
                $this->assertSame('Missed Daily Update', $firstDay['daily_update_status']);
                $this->assertSame('Submitted', $secondDay['daily_update_status']);

                return true;
            });
        $this->travelBack();
    }

    public function test_today_volume_update_is_pending_until_a_subtask_is_submitted(): void
    {
        $this->travelTo('2026-11-11 10:00:00');
        $task = $this->volumeTask(10, '2026-11-11', '2026-11-17', 'VOL-TODAY-PENDING');

        $this->actingAs($this->admin)->get(route('tasks.show', $task))
            ->assertOk()
            ->assertViewHas('volumeSummary', function (array $summary): bool {
                $today = $summary['rows']->first();

                $this->assertSame('Pending', $today['daily_update_status']);
                $this->assertSame("Today's Daily Update Pending", $today['daily_update_label']);
                $this->assertFalse($today['has_update']);

                return true;
            });

        $this->createVolumeSubtask($task, 'Submitted update', '2026-11-11')->assertCreated();

        $this->actingAs($this->admin)->get(route('tasks.show', $task))
            ->assertOk()
            ->assertViewHas('volumeSummary', function (array $summary): bool {
                $today = $summary['rows']->first();

                $this->assertSame('Submitted', $today['daily_update_status']);
                $this->assertTrue($today['has_update']);
                $this->assertSame(1, $summary['today_actual_count']);

                return true;
            });

        $this->travelBack();
    }

    public function test_cumulative_volume_status_distinguishes_behind_and_ahead_output(): void
    {
        $this->travelTo('2026-11-12 10:00:00');
        $behindTask = $this->volumeTask(10, '2026-11-11', '2026-11-17', 'VOL-CUMULATIVE-BEHIND');
        $this->createVolumeSubtask($behindTask, 'Day one', '2026-11-11', completedCount: 1)->assertCreated();
        $this->createVolumeSubtask($behindTask, 'Day two', '2026-11-12', completedCount: 1)->assertCreated();

        $this->actingAs($this->admin)->get(route('tasks.show', $behindTask))
            ->assertOk()
            ->assertViewHas('volumeSummary', function (array $summary): bool {
                $secondDay = $summary['rows']->get(1);
                $this->assertSame(4, $secondDay['cumulative_target']);
                $this->assertSame(2, $secondDay['cumulative_actual']);
                $this->assertSame('Behind', $secondDay['status']);
                $this->assertSame('Behind', $summary['overall_status']);

                return true;
            });

        $aheadTask = $this->volumeTask(10, '2026-11-11', '2026-11-17', 'VOL-CUMULATIVE-AHEAD');
        foreach (range(1, 5) as $number) {
            $this->createVolumeSubtask($aheadTask, "Ahead output {$number}", '2026-11-11')->assertCreated();
        }

        $this->actingAs($this->admin)->get(route('tasks.show', $aheadTask))
            ->assertOk()
            ->assertViewHas('volumeSummary', function (array $summary): bool {
                $firstDay = $summary['rows']->first();
                $this->assertSame(2, $firstDay['cumulative_target']);
                $this->assertSame(5, $firstDay['cumulative_actual']);
                $this->assertSame('Ahead', $firstDay['status']);
                $this->assertSame('Ahead', $summary['overall_status']);

                return true;
            });
        $this->travelBack();
    }

    public function test_same_date_subtask_counts_are_summed(): void
    {
        $this->travelTo('2026-11-11 10:00:00');
        $task = $this->volumeTask(10, '2026-11-11', '2026-11-17', 'VOL-SAME-DATE-SUM');
        foreach (range(1, 4) as $number) {
            $this->createVolumeSubtask($task, "Output {$number}", '2026-11-11')->assertCreated();
        }

        $this->actingAs($this->admin)->get(route('tasks.show', $task))
            ->assertOk()
            ->assertViewHas('volumeSummary', function (array $summary): bool {
                $firstDay = $summary['rows']->first();
                $this->assertSame(4, $firstDay['actual']);
                $this->assertSame(4, $firstDay['cumulative_actual']);
                $this->assertSame('Ahead', $firstDay['status']);
                $this->assertSame(4, $summary['today_actual_count']);

                return true;
            });

        $this->assertDatabaseCount('subtasks', 4);
        $this->assertDatabaseCount('task_volume_updates', 4);
        $this->travelBack();
    }

    public function test_moving_or_deleting_a_volume_subtask_recalculates_later_cumulative_rows(): void
    {
        $this->travelTo('2026-11-12 10:00:00');
        $task = $this->volumeTask(10, '2026-11-11', '2026-11-17', 'VOL-EDIT-CUMULATIVE');
        $firstResponse = $this->createVolumeSubtask($task, 'Earlier output', '2026-11-11')
            ->assertCreated();
        $firstSubtaskId = (int) $firstResponse->json('subtask.id');
        $secondResponse = $this->createVolumeSubtask($task, 'Later output', '2026-11-12')
            ->assertCreated();
        $secondSubtaskId = (int) $secondResponse->json('subtask.id');

        $this->actingAs($this->admin)
            ->patchJson(route('subtasks.update', [$task, $firstSubtaskId]), ['work_date' => '2026-11-12'])
            ->assertOk()
            ->assertJsonPath('subtask.volume_update.work_date', '2026-11-12');
        $this->actingAs($this->admin)->get(route('tasks.show', $task))
            ->assertOk()
            ->assertViewHas('volumeSummary', function (array $summary): bool {
                $secondDay = $summary['rows']->get(1);
                $this->assertSame(2, $secondDay['cumulative_actual']);
                $this->assertSame('Behind', $secondDay['status']);

                return true;
            });

        $this->actingAs($this->admin)->deleteJson(route('subtasks.destroy', [$task, $secondSubtaskId]))->assertOk();
        $this->actingAs($this->admin)->get(route('tasks.show', $task))
            ->assertOk()
            ->assertViewHas('volumeSummary', function (array $summary): bool {
                $this->assertSame(1, $summary['cumulative_actual']);
                $this->assertSame(1, $summary['completed']);
                $this->assertSame(9, $summary['remaining']);

                return true;
            });
        $this->travelBack();
    }

    public function test_working_day_holidays_change_the_daily_target_and_skip_cumulative_dates(): void
    {
        config(['tasks.volume_holidays' => ['2026-11-12']]);
        $task = $this->volumeTask(10, '2026-11-11', '2026-11-17', 'VOL-CUMULATIVE-HOLIDAY');
        $summary = app(VolumeTaskService::class)->summary($task);

        $this->assertSame(4, $summary['working_days']);
        $this->assertSame(3, $summary['minimum_daily_count']);
        $this->assertSame(
            ['2026-11-11', '2026-11-13', '2026-11-16', '2026-11-17'],
            $summary['rows']->map(fn (array $row): string => $row['date']->toDateString())->all(),
        );
        $this->assertSame(6, $summary['rows']->get(1)['cumulative_target']);
    }

    public function test_volume_status_reports_completed_and_overdue_independently_of_daily_updates(): void
    {
        $this->travelTo('2026-11-11 10:00:00');
        $completedTask = $this->volumeTask(5, '2026-11-11', '2026-11-17', 'VOL-EARLY-COMPLETED');
        foreach (range(1, 5) as $number) {
            $this->createVolumeSubtask($completedTask, "Complete volume {$number}", '2026-11-11')->assertCreated();
        }

        $this->actingAs($this->admin)->get(route('tasks.show', $completedTask))
            ->assertOk()
            ->assertViewHas('volumeSummary', fn (array $summary): bool => $summary['overall_status'] === 'Completed'
                && $summary['remaining'] === 0);

        $this->travelTo('2026-11-18 10:00:00');
        $overdueTask = $this->volumeTask(10, '2026-11-11', '2026-11-17', 'VOL-DEADLINE-OVERDUE');
        $this->actingAs($this->admin)->get(route('tasks.show', $overdueTask))
            ->assertOk()
            ->assertViewHas('volumeSummary', function (array $summary): bool {
                $this->assertSame('Overdue', $summary['overall_status']);
                $this->assertSame('Missed Daily Update', $summary['rows']->first()['daily_update_status']);

                return true;
            });
        $this->travelBack();
    }

    public function test_volume_output_rejects_non_working_dates_and_the_separate_daily_update_route(): void
    {
        $this->travelTo('2026-11-16 10:00:00');
        $task = $this->volumeTask(10, '2026-11-09', '2026-11-20', 'VOL-TEST-WORKDAY');

        $this->actingAs($this->admin)->postJson(route('subtasks.store', $task), [
            'title' => 'Weekend output',
            'work_date' => '2026-11-14',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('work_date');

        $subtask = Subtask::query()->create([
            'task_id' => $task->id,
            'created_by' => $this->admin->id,
            'assigned_to' => $this->admin->id,
            'title' => 'Existing volume subtask',
        ]);
        $this->actingAs($this->admin)
            ->postJson(route('dwr.subtasks.daily-updates.store', [$task, $subtask]), [
                'work_date' => '2026-11-16',
                'work_description' => 'This route is not the Volume entry flow.',
                'completed_count' => 1,
            ])
            ->assertUnprocessable();

        $this->assertDatabaseCount('task_volume_updates', 0);
        $this->assertDatabaseCount('subtask_daily_updates', 0);
    }

    public function test_employee_cannot_change_another_employees_volume_subtask(): void
    {
        $task = $this->volumeTask(10, '2026-11-09', '2026-11-20', 'VOL-TEST-AUTH');
        $firstEmployee = $this->employee();
        $secondEmployee = $this->employee();
        $this->assignTaskTo($task, $firstEmployee);
        $this->assignTaskTo($task, $secondEmployee);

        $subtaskResponse = $this->createVolumeSubtask($task, 'Assigned volume subtask', '2026-11-10', $firstEmployee)
            ->assertCreated();
        $subtaskId = (int) $subtaskResponse->json('subtask.id');

        $this->actingAs($secondEmployee)
            ->patchJson(route('subtasks.update', [$task, $subtaskId]), [
                'work_date' => '2026-11-10',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('task_volume_updates', [
            'task_id' => $task->id,
            'subtask_id' => $subtaskId,
            'user_id' => $firstEmployee->id,
        ]);
    }

    public function test_volume_task_view_uses_subtask_entry_fields_without_a_separate_daily_output_action(): void
    {
        $this->travelTo('2026-11-10 10:00:00');
        $task = $this->volumeTask(10, '2026-11-09', '2026-11-13', 'VOL-TEST-VIEW');

        $this->actingAs($this->admin)
            ->get(route('tasks.show', $task))
            ->assertOk()
            ->assertDontSee('Completed output (count)')
            ->assertDontSee('Actual output (count)')
            ->assertSee('Work date')
            ->assertSee('Actual Output')
            ->assertDontSee('Cumulative Target')
            ->assertSee('Cumulative Actual')
            ->assertDontSee('Daily Update Status')
            ->assertDontSee('Record daily output')
            ->assertDontSee('subtaskDailyUpdateModal');
    }

    public function test_task_list_uses_volume_start_date_when_deciding_if_daily_update_is_due(): void
    {
        $this->travelTo('2026-11-11 10:00:00');
        $task = $this->volumeTask(10, '2026-11-12', '2026-11-13', 'VOL-FUTURE-START');
        $task->forceFill(['start_date' => '2026-11-10'])->save();

        $html = $this->actingAs($this->admin)->get(route('tasks.index'))->assertOk()->getContent();

        $this->assertStringContainsString('data-task-id="'.$task->id.'"', $html);
        $this->assertStringNotContainsString('No daily update received today.', $html);
        $this->travelBack();
    }

    private function volumeTask(int $total, string $start, string $deadline, string $taskCode): Task
    {
        $task = Task::factory()->create([
            'task_code' => $taskCode,
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'created_by' => $this->admin->id,
            'task_type' => 'volume',
            'status' => 'in_progress',
            'start_date' => $start,
            'due_date' => $deadline,
            'original_deadline' => $deadline,
            'progress_percentage' => 0,
        ]);
        $task->volume()->create([
            'total_volume' => $total,
            'start_date' => $start,
            'deadline_date' => $deadline,
        ]);

        return $task;
    }

    private function createVolumeSubtask(
        Task $task,
        string $title,
        ?string $workDate,
        ?User $assignee = null,
        ?int $completedCount = null,
    ): TestResponse {
        $payload = ['title' => $title];
        if ($workDate !== null) {
            $payload['work_date'] = $workDate;
        }
        if ($assignee !== null) {
            $payload['assigned_to'] = $assignee->id;
        }

        return $this->actingAs($this->admin)->postJson(route('subtasks.store', $task), $payload);
    }

    private function employee(): User
    {
        $employee = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'status' => 'active',
        ]);
        $employee->assignRole(Role::query()->where('name', 'Employee')->firstOrFail());

        return $employee;
    }

    private function assignTaskTo(Task $task, User $employee): void
    {
        $task->assignments()->create([
            'user_id' => $employee->id,
            'assigned_by' => $this->admin->id,
            'reason' => 'Volume task assignment',
            'assigned_at' => now(),
            'is_active' => true,
        ]);
    }
}
