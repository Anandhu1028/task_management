<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\DailyAccountabilityEvent;
use App\Models\DailyAccountabilitySetting;
use App\Models\DailyTask;
use App\Models\Department;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskStatusHistory;
use App\Models\User;
use App\Services\DailyTaskAccountabilityService;
use App\Services\DailyTaskActivityService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DailyTaskAccountabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
    }

    public function test_missing_daily_update_warning_is_recorded_once_and_remains_after_a_late_submission(): void
    {
        $this->travelTo(Carbon::parse('2026-10-31 10:00:00', config('app.timezone')));
        [$task, $dailyTask, $employee] = $this->createDailyTask('2026-10-31');
        $accountability = app(DailyTaskAccountabilityService::class);

        $this->travelTo(Carbon::parse('2026-10-31 18:01:00', config('app.timezone')));
        $accountability->processDueReports();
        $accountability->processDueReports();

        $this->assertDatabaseHas('daily_accountability_events', [
            'task_id' => $task->id,
            'daily_task_id' => $dailyTask->id,
            'employee_id' => $employee->id,
            'event_type' => 'missed',
        ]);
        $this->assertSame(1, DailyAccountabilityEvent::query()->where('event_type', 'missed')->count());

        $this->travelTo(Carbon::parse('2026-10-31 19:15:00', config('app.timezone')));
        $history = app(DailyTaskActivityService::class)->record(
            $dailyTask,
            $employee,
            'daily_task_update_submitted',
            'in_progress',
            'in_progress',
            'Completed the API validation work.',
        );

        $this->assertSame('2026-10-31', $history->activity_date?->toDateString());
        $this->assertSame('19:15', $history->created_at?->timezone(config('app.timezone'))->format('H:i'));
        $this->assertSame(1, DailyAccountabilityEvent::query()->where('event_type', 'missed')->count());
        $this->assertDatabaseHas('daily_tasks', [
            'id' => $dailyTask->id,
            'status' => 'in_progress',
        ]);

        $admin = User::factory()->create([
            'company_id' => $employee->company_id,
            'department_id' => $employee->department_id,
        ]);
        $admin->assignRole('Admin');
        $timeline = $this->actingAs($admin)->get(route('tasks.show', $task))->assertOk()->getContent();
        $timelineStart = strpos($timeline, 'id="panel-timeline"');
        $timelineEnd = strpos($timeline, 'id="panel-milestones"');
        $this->assertNotFalse($timelineStart);
        $this->assertNotFalse($timelineEnd);
        $timeline = substr($timeline, $timelineStart, $timelineEnd - $timelineStart);
        $this->assertSame(1, preg_match("/data-timeline-model='([^']+)'/", $timeline, $timelineModelMatch));
        $timelineModel = json_decode(
            html_entity_decode($timelineModelMatch[1], ENT_QUOTES | ENT_HTML5),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $this->assertFalse(collect($timelineModel['activities'])->contains(
            fn (array $activity): bool => str_starts_with((string) $activity['type'], 'daily_task_')
                || str_starts_with((string) $activity['type'], 'daily_accountability_'),
        ));
        $this->assertTrue(collect($timelineModel['dailyUpdates'])->contains(
            fn (array $activity): bool => $activity['type'] === 'daily_accountability_missed',
        ));
    }

    public function test_missing_day_without_a_daily_task_is_backend_processed_after_cutoff_and_keeps_history(): void
    {
        $this->travelTo(Carbon::parse('2026-10-31 10:00:00', config('app.timezone')));
        [$task, $employee] = $this->createAssignedTaskWithoutDailyTask('2026-10-31');
        [$futureAssignedTask] = $this->createAssignedTaskWithoutDailyTask('2026-10-31', '19:00');
        [$heldTask] = $this->createAssignedTaskWithoutDailyTask('2026-10-31');
        $heldHistory = $heldTask->statusHistory()->create([
            'changed_by' => $employee->id,
            'old_status' => 'in_progress',
            'new_status' => 'on_hold',
            'event_type' => 'hold',
            'remarks' => 'Waiting for a dependency.',
        ]);
        $heldHistory->forceFill([
            'created_at' => Carbon::parse('2026-10-31 17:00:00', config('app.timezone')),
            'updated_at' => Carbon::parse('2026-10-31 17:00:00', config('app.timezone')),
        ])->save();
        [$completedTask] = $this->createAssignedTaskWithoutDailyTask('2026-10-31');
        $completedTask->update([
            'status' => 'completed',
            'completed_at' => Carbon::parse('2026-10-31 17:00:00', config('app.timezone')),
        ]);
        $this->travelTo(Carbon::parse('2026-10-31 17:59:00', config('app.timezone')));
        app(DailyTaskAccountabilityService::class)->processDueReports();
        $this->assertDatabaseMissing('daily_accountability_events', [
            'task_id' => $task->id,
            'event_type' => 'missed',
        ]);

        $this->travelTo(Carbon::parse('2026-10-31 18:01:00', config('app.timezone')));
        app(DailyTaskAccountabilityService::class)->processDueReports();
        app(DailyTaskAccountabilityService::class)->processDueReports();

        $this->assertSame(1, DailyAccountabilityEvent::query()
            ->where('task_id', $task->id)
            ->whereNull('daily_task_id')
            ->where('event_type', 'missed')
            ->count());
        $this->assertDatabaseHas('daily_accountability_events', [
            'task_id' => $task->id,
            'daily_task_id' => null,
            'employee_id' => $employee->id,
            'event_type' => 'missed',
            'description' => 'No Daily Task Created. No Daily Update Submitted. No Work Progress Recorded by the cutoff.',
        ]);
        foreach ([$futureAssignedTask, $heldTask, $completedTask] as $notMissingTask) {
            $this->assertDatabaseMissing('daily_accountability_events', [
                'task_id' => $notMissingTask->id,
                'event_type' => 'missed',
            ]);
        }

        $this->travelTo(Carbon::parse('2026-10-31 19:15:00', config('app.timezone')));
        $lateDailyTask = DailyTask::factory()->create([
            'task_id' => $task->id,
            'assigned_to' => $employee->id,
            'created_by' => $employee->id,
            'title' => 'Late task entry',
            'work_date' => '2026-10-31',
            'original_work_date' => '2026-10-31',
            'status' => 'in_progress',
        ]);
        app(DailyTaskActivityService::class)->record(
            $lateDailyTask,
            $employee,
            'daily_task_update_submitted',
            'in_progress',
            'in_progress',
            'Recorded the work after the cutoff.',
        );

        $this->assertDatabaseHas('daily_accountability_events', [
            'task_id' => $task->id,
            'daily_task_id' => null,
            'event_type' => 'missed',
        ]);
        $this->assertDatabaseHas('task_status_histories', [
            'daily_task_id' => $lateDailyTask->id,
            'event_type' => 'daily_task_update_submitted',
        ]);

        $admin = User::factory()->create([
            'company_id' => $employee->company_id,
            'department_id' => $employee->department_id,
        ]);
        $admin->assignRole('Admin');
        $timelineHtml = $this->actingAs($admin)->get(route('tasks.show', $task))->assertOk()->getContent();
        $this->assertSame(1, preg_match("/data-timeline-model='([^']+)'/", $timelineHtml, $timelineModelMatch));
        $timelineModel = json_decode(
            html_entity_decode($timelineModelMatch[1], ENT_QUOTES | ENT_HTML5),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $this->assertFalse(collect($timelineModel['activities'])->contains(
            fn (array $activity): bool => str_starts_with((string) $activity['type'], 'daily_'),
        ));
        $dailyUpdates = collect($timelineModel['dailyUpdates']);
        $this->assertTrue($dailyUpdates->contains(
            fn (array $activity): bool => $activity['type'] === 'daily_accountability_missed'
                && str_contains($activity['detail'], 'No Daily Task Created')
        ));
    }

    public function test_on_time_daily_update_satisfies_the_deadline_without_completing_the_daily_task(): void
    {
        $this->travelTo(Carbon::parse('2026-10-31 10:00:00', config('app.timezone')));
        [$task, $dailyTask, $employee] = $this->createDailyTask('2026-10-31');

        $this->travelTo(Carbon::parse('2026-10-31 18:00:00', config('app.timezone')));
        $this->actingAs($employee)
            ->post(route('daily-tasks.action', [$task, $dailyTask, 'update']), [
                'remarks' => 'Reviewed the API response shape.',
            ])
            ->assertRedirect(route('tasks.show', $task).'#panel-daily-tasks');

        $this->travelTo(Carbon::parse('2026-10-31 18:01:00', config('app.timezone')));
        app(DailyTaskAccountabilityService::class)->processDueReports();

        $history = TaskStatusHistory::query()
            ->where('daily_task_id', $dailyTask->id)
            ->where('event_type', 'daily_task_update_submitted')
            ->firstOrFail();
        $this->assertSame('18:00', $history->created_at?->timezone(config('app.timezone'))->format('H:i'));
        $this->assertDatabaseMissing('daily_accountability_events', [
            'daily_task_id' => $dailyTask->id,
            'event_type' => 'missed',
        ]);
        $this->assertDatabaseHas('daily_tasks', [
            'id' => $dailyTask->id,
            'status' => 'in_progress',
        ]);
    }

    public function test_admin_exemption_clears_a_missing_update_warning(): void
    {
        $this->travelTo(Carbon::parse('2026-10-31 10:00:00', config('app.timezone')));
        [$task, $dailyTask, $employee] = $this->createDailyTask('2026-10-31');
        $this->travelTo(Carbon::parse('2026-10-31 18:01:00', config('app.timezone')));
        app(DailyTaskAccountabilityService::class)->processDueReports();
        $admin = User::factory()->create([
            'company_id' => $employee->company_id,
            'department_id' => $employee->department_id,
        ]);
        $admin->assignRole('Admin');
        $this->actingAs($admin)
            ->post(route('daily-accountability.settings.exemptions.store'), [
                'employee_id' => $employee->id,
                'starts_on' => '2026-10-31',
                'ends_on' => '2026-10-31',
                'reason' => 'Approved leave',
            ])
            ->assertRedirect();

        $this->assertDatabaseMissing('daily_accountability_events', [
            'task_id' => $task->id,
            'daily_task_id' => $dailyTask->id,
            'event_type' => 'missed',
        ]);
    }

    public function test_sunday_is_not_processed_as_a_working_day(): void
    {
        $this->travelTo(Carbon::parse('2026-11-01 10:00:00', config('app.timezone')));
        [, $dailyTask] = $this->createDailyTask('2026-11-01');

        $this->travelTo(Carbon::parse('2026-11-01 19:00:00', config('app.timezone')));
        app(DailyTaskAccountabilityService::class)->processDueReports();

        $this->assertDatabaseMissing('daily_accountability_events', [
            'daily_task_id' => $dailyTask->id,
        ]);
    }

    public function test_admin_can_view_settings_and_other_users_cannot(): void
    {
        $company = Company::factory()->create();
        $department = Department::factory()->create(['company_id' => $company->id]);
        $admin = User::factory()->create([
            'company_id' => $company->id,
            'department_id' => $department->id,
        ]);
        $admin->assignRole('Admin');
        $employee = User::factory()->create([
            'company_id' => $company->id,
            'department_id' => $department->id,
        ]);
        $employee->assignRole('Employee');

        $this->actingAs($admin)
            ->get(route('daily-accountability.settings.index'))
            ->assertOk()
            ->assertSee('18:00')
            ->assertDontSee('penalty');
        $this->assertDatabaseHas('daily_accountability_settings', ['id' => 1]);
        $this->assertFalse(Schema::hasColumn('daily_accountability_settings', 'missed_penalty_points'));
        $this->assertFalse(Schema::hasColumn('daily_accountability_events', 'points'));

        $this->actingAs($employee)
            ->get(route('daily-accountability.settings.index'))
            ->assertForbidden();
    }

    public function test_admin_can_update_the_cutoff_and_invalid_workdays_are_rejected(): void
    {
        $company = Company::factory()->create();
        $department = Department::factory()->create(['company_id' => $company->id]);
        $admin = User::factory()->create([
            'company_id' => $company->id,
            'department_id' => $department->id,
        ]);
        $admin->assignRole('Admin');

        $this->actingAs($admin)
            ->put(route('daily-accountability.settings.update'), [
                'cutoff_time' => '17:30',
                'working_days' => ['monday', 'saturday'],
            ])
            ->assertRedirect();

        $settings = DailyAccountabilitySetting::current();
        $this->assertSame('17:30', $settings->cutoff_time);
        $this->assertSame(['monday', 'saturday'], $settings->working_days);

        $this->put(route('daily-accountability.settings.update'), [
            'cutoff_time' => '17:30',
            'working_days' => [],
        ])->assertSessionHasErrors('working_days');
    }

    public function test_employee_accountability_report_does_not_show_another_employees_records(): void
    {
        $this->travelTo(Carbon::parse('2026-10-31 10:00:00', config('app.timezone')));
        [$task, $ownDailyTask, $employee] = $this->createDailyTask('2026-10-31');
        $otherEmployee = User::factory()->create([
            'company_id' => $employee->company_id,
            'department_id' => $employee->department_id,
        ]);
        $otherEmployee->assignRole('Employee');
        $otherDailyTask = DailyTask::factory()->create([
            'task_id' => $task->id,
            'assigned_to' => $otherEmployee->id,
            'created_by' => $employee->id,
            'title' => 'Other employee private update',
            'work_date' => '2026-10-31',
            'original_work_date' => '2026-10-31',
        ]);
        DailyAccountabilityEvent::factory()->create([
            'task_id' => $task->id,
            'daily_task_id' => $ownDailyTask->id,
            'employee_id' => $employee->id,
            'employee_name' => $employee->name,
            'task_title' => $task->title,
            'daily_task_title' => $ownDailyTask->title,
            'report_date' => '2026-10-31',
            'event_key' => 'own-accountability-event',
        ]);
        DailyAccountabilityEvent::factory()->create([
            'task_id' => $task->id,
            'daily_task_id' => $ownDailyTask->id,
            'employee_id' => $employee->id,
            'employee_name' => $employee->name,
            'task_title' => $task->title,
            'daily_task_title' => $ownDailyTask->title,
            'event_type' => 'late',
            'description' => 'A legacy late-update record is not a warning.',
            'report_date' => '2026-10-31',
            'event_key' => 'legacy-late-accountability-event',
        ]);
        DailyAccountabilityEvent::factory()->create([
            'task_id' => $task->id,
            'daily_task_id' => $otherDailyTask->id,
            'employee_id' => $otherEmployee->id,
            'employee_name' => $otherEmployee->name,
            'task_title' => $task->title,
            'daily_task_title' => $otherDailyTask->title,
            'report_date' => '2026-10-31',
            'event_key' => 'other-accountability-event',
        ]);

        $this->actingAs($employee)
            ->get(route('daily-accountability.index', [
                'from_date' => '2026-10-31',
                'to_date' => '2026-10-31',
            ]))
            ->assertOk()
            ->assertSee($employee->name)
            ->assertDontSee($otherEmployee->name)
            ->assertDontSee('Other employee private update')
            ->assertDontSee('A legacy late-update record is not a warning.');
    }

    /**
     * @return array{0: Task, 1: DailyTask, 2: User}
     */
    private function createDailyTask(string $workDate): array
    {
        $company = Company::factory()->create();
        $department = Department::factory()->create(['company_id' => $company->id]);
        $employee = User::factory()->create([
            'company_id' => $company->id,
            'department_id' => $department->id,
        ]);
        $employee->assignRole('Employee');
        $task = Task::factory()->create([
            'company_id' => $company->id,
            'department_id' => $department->id,
            'created_by' => $employee->id,
            'assigned_to' => $employee->id,
            'status' => 'in_progress',
            'start_date' => $workDate,
            'due_date' => $workDate,
        ]);
        $dailyTask = DailyTask::factory()->create([
            'task_id' => $task->id,
            'assigned_to' => $employee->id,
            'created_by' => $employee->id,
            'title' => 'Implement API validation',
            'work_date' => $workDate,
            'original_work_date' => $workDate,
            'status' => 'in_progress',
        ]);

        return [$task, $dailyTask, $employee];
    }

    /**
     * @return array{0: Task, 1: User}
     */
    private function createAssignedTaskWithoutDailyTask(string $workDate, string $assignedTime = '09:00'): array
    {
        $company = Company::factory()->create();
        $department = Department::factory()->create(['company_id' => $company->id]);
        $employee = User::factory()->create([
            'company_id' => $company->id,
            'department_id' => $department->id,
        ]);
        $employee->assignRole('Employee');
        $task = Task::factory()->create([
            'company_id' => $company->id,
            'department_id' => $department->id,
            'created_by' => $employee->id,
            'assigned_to' => $employee->id,
            'status' => 'in_progress',
            'start_date' => $workDate,
            'due_date' => $workDate,
        ]);
        TaskAssignment::query()->create([
            'task_id' => $task->id,
            'user_id' => $employee->id,
            'assigned_by' => $employee->id,
            'assigned_at' => Carbon::parse($workDate.' '.$assignedTime, config('app.timezone')),
            'is_active' => true,
        ]);

        return [$task, $employee];
    }
}
