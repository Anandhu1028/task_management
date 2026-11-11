<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\RecurringTask;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\User;
use App\Services\RecurringTaskGenerationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RecurringTaskVisibilityTest extends TestCase
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

    public function test_daily_occurrences_are_visible_only_inside_inclusive_date_and_time_boundaries(): void
    {
        $schedule = $this->createSchedule([
            'start_date' => '2026-11-09',
            'end_date' => '2026-11-13',
            'start_time' => '09:00:00',
            'end_time' => '22:00:00',
        ]);
        $occurrences = collect([
            '2026-11-09',
            '2026-11-10',
            '2026-11-11',
            '2026-11-12',
            '2026-11-13',
        ])->mapWithKeys(fn (string $date): array => [$date => $this->createOccurrence($schedule, $date)->id]);

        $visibilityCases = [
            ['2026-11-08 11:00:00', null],
            ['2026-11-09 08:59:00', null],
            ['2026-11-09 09:00:00', $occurrences['2026-11-09']],
            ['2026-11-09 13:00:00', $occurrences['2026-11-09']],
            ['2026-11-09 21:59:00', $occurrences['2026-11-09']],
            ['2026-11-09 22:00:00', $occurrences['2026-11-09']],
            ['2026-11-09 22:00:01', null],
            ['2026-11-10 09:00:00', $occurrences['2026-11-10']],
            ['2026-11-11 14:00:00', $occurrences['2026-11-11']],
            ['2026-11-12 17:00:00', $occurrences['2026-11-12']],
            ['2026-11-13 09:00:00', $occurrences['2026-11-13']],
            ['2026-11-13 22:00:00', $occurrences['2026-11-13']],
            ['2026-11-13 22:00:01', null],
            ['2026-11-14 11:00:00', null],
        ];

        foreach ($visibilityCases as [$instant, $expectedTaskId]) {
            $this->travelTo(CarbonImmutable::parse($instant, 'UTC'));

            $response = $this->actingAsDesktop($this->admin)
                ->getJson('/api/tasks?search=Daily%20visibility%20fixture&per_page=100')
                ->assertOk();

            $taskIds = collect($response->json('data'))->pluck('id')->all();
            if ($expectedTaskId === null) {
                $this->assertSame([], $taskIds, "Unexpected task visible at {$instant}.");
            } else {
                $this->assertSame([$expectedTaskId], $taskIds, "Expected the occurrence to be visible at {$instant}.");
            }
            $this->assertSame(count($taskIds), $response->json('meta.total'));
            $this->assertSame(
                Task::query()->visibleTo($this->admin)->availableInRecurringWindow()->count(),
                $response->json('counts.total'),
            );
        }

        $this->assertDatabaseHas('tasks', [
            'id' => $occurrences['2026-11-13'],
            'status' => 'assigned',
        ]);
    }

    public function test_weekly_and_monthly_occurrences_follow_their_configured_frequency(): void
    {
        $weeklySchedule = $this->createSchedule([
            'title' => 'Weekly visibility fixture',
            'repeat_frequency' => 'weekly',
            'start_date' => '2026-11-09',
            'end_date' => '2026-11-23',
        ]);
        $weeklyMonday = $this->createOccurrence($weeklySchedule, '2026-11-09')->id;
        $this->createOccurrence($weeklySchedule, '2026-11-10');
        $nextWeeklyMonday = $this->createOccurrence($weeklySchedule, '2026-11-16')->id;

        $monthlySchedule = $this->createSchedule([
            'title' => 'Monthly visibility fixture',
            'repeat_frequency' => 'monthly',
            'start_date' => '2026-01-31',
            'end_date' => '2026-04-30',
        ]);
        $februaryOccurrence = $this->createOccurrence($monthlySchedule, '2026-02-28')->id;
        $marchOccurrence = $this->createOccurrence($monthlySchedule, '2026-03-28')->id;
        $this->createOccurrence($monthlySchedule, '2026-03-31');

        $this->travelTo(CarbonImmutable::parse('2026-11-09 09:00:00', 'UTC'));
        $this->assertSame([$weeklyMonday], $this->apiTaskIds('Weekly visibility fixture'));

        $this->travelTo(CarbonImmutable::parse('2026-11-10 09:00:00', 'UTC'));
        $this->assertSame([], $this->apiTaskIds('Weekly visibility fixture'));

        $this->travelTo(CarbonImmutable::parse('2026-11-16 09:00:00', 'UTC'));
        $this->assertSame([$nextWeeklyMonday], $this->apiTaskIds('Weekly visibility fixture'));

        $this->travelTo(CarbonImmutable::parse('2026-02-28 09:00:00', 'UTC'));
        $this->assertSame([$februaryOccurrence], $this->apiTaskIds('Monthly visibility fixture'));

        $this->travelTo(CarbonImmutable::parse('2026-03-28 09:00:00', 'UTC'));
        $this->assertSame([$marchOccurrence], $this->apiTaskIds('Monthly visibility fixture'));

        $this->travelTo(CarbonImmutable::parse('2026-03-31 09:00:00', 'UTC'));
        $this->assertSame([], $this->apiTaskIds('Monthly visibility fixture'));
    }

    public function test_normal_tasks_remain_visible_and_invalid_or_missing_recurring_data_is_hidden(): void
    {
        $normalTask = $this->createTask('Normal visibility fixture');
        $missingSchedule = $this->createTask('Missing schedule visibility fixture', 'recurring');
        $invalidSchedule = $this->createSchedule([
            'title' => 'Invalid frequency visibility fixture',
            'repeat_frequency' => 'yearly',
            'start_date' => '2026-11-09',
            'end_date' => '2026-11-13',
        ]);
        $this->createOccurrence($invalidSchedule, '2026-11-09');
        $validSchedule = $this->createSchedule([
            'title' => 'Missing occurrence window fixture',
            'start_date' => '2026-11-09',
            'end_date' => '2026-11-13',
        ]);
        $this->createOccurrence($validSchedule, '2026-11-09', [
            'available_from' => null,
            'expires_at' => null,
        ]);

        $this->travelTo(CarbonImmutable::parse('2026-11-09 12:00:00', 'UTC'));
        $this->assertSame([$normalTask->id], $this->apiTaskIds('Normal visibility fixture'));
        $this->assertSame([], $this->apiTaskIds('Missing schedule visibility fixture'));
        $this->assertSame([], $this->apiTaskIds('Invalid frequency visibility fixture'));
        $this->assertSame([], $this->apiTaskIds('Missing occurrence window fixture'));
        $this->assertDatabaseHas('tasks', ['id' => $missingSchedule->id, 'task_type' => 'recurring']);
    }

    public function test_schedule_timezone_is_used_for_daily_visibility(): void
    {
        $schedule = $this->createSchedule([
            'title' => 'Timezone visibility fixture',
            'start_date' => '2026-11-09',
            'end_date' => '2026-11-09',
            'start_time' => '09:00:00',
            'end_time' => '22:00:00',
            'timezone' => 'America/Los_Angeles',
        ]);
        $occurrence = $this->createOccurrence($schedule, '2026-11-09')->id;

        $this->travelTo(CarbonImmutable::parse('2026-11-09 16:59:59', 'UTC'));
        $this->assertSame([], $this->apiTaskIds('Timezone visibility fixture'));

        $this->travelTo(CarbonImmutable::parse('2026-11-09 17:00:00', 'UTC'));
        $this->assertSame([$occurrence], $this->apiTaskIds('Timezone visibility fixture'));

        $this->travelTo(CarbonImmutable::parse('2026-11-10 06:00:00', 'UTC'));
        $this->assertSame([$occurrence], $this->apiTaskIds('Timezone visibility fixture'));

        $this->travelTo(CarbonImmutable::parse('2026-11-10 06:00:01', 'UTC'));
        $this->assertSame([], $this->apiTaskIds('Timezone visibility fixture'));
    }

    public function test_occurrence_status_is_not_changed_at_the_inclusive_end_time(): void
    {
        $schedule = $this->createSchedule([
            'start_date' => '2026-11-09',
            'end_date' => '2026-11-09',
            'start_time' => '09:00:00',
            'end_time' => '22:00:00',
        ]);
        $occurrence = $this->createOccurrence($schedule, '2026-11-09');
        $generator = app(RecurringTaskGenerationService::class);

        $this->travelTo(CarbonImmutable::parse('2026-11-09 22:00:00', 'UTC'));
        $generator->generateDueOccurrences();
        $this->assertDatabaseHas('tasks', ['id' => $occurrence->id, 'status' => 'assigned']);

        $this->travelTo(CarbonImmutable::parse('2026-11-09 22:00:01', 'UTC'));
        $generator->generateDueOccurrences();
        $this->assertDatabaseHas('tasks', ['id' => $occurrence->id, 'status' => 'missed']);
    }

    public function test_schedule_completion_uses_the_schedule_timezone(): void
    {
        $schedule = $this->createSchedule([
            'start_date' => '2026-11-09',
            'end_date' => '2026-11-09',
            'timezone' => 'America/Los_Angeles',
        ]);
        $this->createOccurrence($schedule, '2026-11-09');
        $generator = app(RecurringTaskGenerationService::class);

        $this->travelTo(CarbonImmutable::parse('2026-11-10 07:30:00', 'UTC'));
        $generator->generateDueOccurrences();
        $this->assertDatabaseHas('recurring_tasks', [
            'id' => $schedule->id,
            'status' => 'active',
        ]);

        $this->travelTo(CarbonImmutable::parse('2026-11-10 08:00:00', 'UTC'));
        $generator->generateDueOccurrences();
        $this->assertDatabaseHas('recurring_tasks', [
            'id' => $schedule->id,
            'status' => 'completed',
        ]);
    }

    public function test_generated_occurrence_is_visible_only_during_its_window_and_kept_after_expiration(): void
    {
        $schedule = $this->createSchedule([
            'title' => 'Generated occurrence visibility fixture',
            'start_date' => '2026-11-09',
            'end_date' => '2026-11-09',
            'start_time' => '09:00:00',
            'end_time' => '22:00:00',
        ]);
        $poc = $this->createEmployee();
        $assignee = $this->createEmployee();
        $schedule->task->pocs()->sync([$poc->id]);
        TaskAssignment::query()->create([
            'task_id' => $schedule->task_id,
            'user_id' => $assignee->id,
            'assigned_by' => $this->admin->id,
            'reason' => 'Recurring visibility test assignment',
            'assigned_at' => now(),
            'is_active' => true,
        ]);
        $generator = app(RecurringTaskGenerationService::class);

        $this->travelTo(CarbonImmutable::parse('2026-11-09 08:59:00', 'UTC'));
        $this->assertSame(0, $generator->generateDueOccurrences());
        $this->assertSame(0, $schedule->occurrences()->count());

        $this->travelTo(CarbonImmutable::parse('2026-11-09 09:00:00', 'UTC'));
        $this->assertSame(1, $generator->generateDueOccurrences());
        $occurrence = $schedule->occurrences()->sole();
        $this->assertSame('recurring', $occurrence->task_type);
        $this->assertSame($assignee->id, $occurrence->assigned_to);

        $response = $this->actingAsDesktop($assignee)
            ->getJson('/api/tasks?search=Generated%20occurrence%20visibility%20fixture&per_page=100')
            ->assertOk();
        $this->assertSame([$occurrence->id], collect($response->json('data'))->pluck('id')->all());

        $this->travelTo(CarbonImmutable::parse('2026-11-09 22:00:01', 'UTC'));
        $this->assertSame(0, $generator->generateDueOccurrences());
        $this->assertDatabaseHas('tasks', [
            'id' => $occurrence->id,
            'status' => 'missed',
        ]);
        $this->assertSame(1, $schedule->occurrences()->count());

        $response = $this->actingAsDesktop($assignee)
            ->getJson('/api/tasks?search=Generated%20occurrence%20visibility%20fixture&per_page=100')
            ->assertOk();
        $this->assertSame([], collect($response->json('data'))->pluck('id')->all());
    }

    public function test_task_visibility_keeps_existing_user_assignment_scope(): void
    {
        $employee = $this->createEmployee();
        $schedule = $this->createSchedule([
            'title' => 'Permission visibility fixture',
            'start_date' => '2026-11-09',
            'end_date' => '2026-11-09',
        ]);
        $assignedOccurrence = $this->createOccurrence($schedule, '2026-11-09', [
            'assigned_to' => $employee->id,
        ]);
        TaskAssignment::query()->create([
            'task_id' => $assignedOccurrence->id,
            'user_id' => $employee->id,
            'assigned_by' => $this->admin->id,
            'reason' => 'Recurring visibility test assignment',
            'assigned_at' => now(),
            'is_active' => true,
        ]);
        $unassignedSchedule = $this->createSchedule([
            'title' => 'Permission visibility fixture unassigned',
            'start_date' => '2026-11-09',
            'end_date' => '2026-11-09',
        ]);
        $this->createOccurrence($unassignedSchedule, '2026-11-09', ['assigned_to' => null]);

        $this->travelTo(CarbonImmutable::parse('2026-11-09 12:00:00', 'UTC'));
        $response = $this->actingAsDesktop($employee)
            ->getJson('/api/tasks?search=Permission%20visibility%20fixture&per_page=100')
            ->assertOk();

        $this->assertSame([$assignedOccurrence->id], collect($response->json('data'))->pluck('id')->all());
    }

    public function test_web_and_pwa_task_lists_hide_occurrences_outside_the_schedule_window(): void
    {
        $schedule = $this->createSchedule([
            'title' => 'List visibility fixture schedule',
            'start_date' => '2026-11-09',
            'end_date' => '2026-11-09',
        ]);
        $this->createOccurrence($schedule, '2026-11-09', [
            'title' => 'List visibility fixture occurrence',
        ]);
        $this->travelTo(CarbonImmutable::parse('2026-11-09 12:00:00', 'UTC'));

        $this->actingAs($this->admin)
            ->get(route('tasks.index', ['search' => 'List visibility fixture']))
            ->assertSee('List visibility fixture occurrence')
            ->assertDontSee('List visibility fixture schedule');
        $this->actingAs($this->admin)
            ->get(route('pwa.tasks.index', ['search' => 'List visibility fixture']))
            ->assertSee('List visibility fixture occurrence')
            ->assertDontSee('List visibility fixture schedule');

        $this->travelTo(CarbonImmutable::parse('2026-11-09 22:00:01', 'UTC'));
        $this->actingAs($this->admin)
            ->get(route('tasks.index', ['search' => 'List visibility fixture']))
            ->assertDontSee('List visibility fixture occurrence');
        $this->actingAs($this->admin)
            ->get(route('pwa.tasks.index', ['search' => 'List visibility fixture']))
            ->assertDontSee('List visibility fixture occurrence');
    }

    public function test_task_list_refreshes_at_the_next_schedule_boundary(): void
    {
        $this->createSchedule([
            'title' => 'Refresh visibility fixture',
            'start_date' => '2026-11-09',
            'end_date' => '2026-11-13',
            'start_time' => '09:00:00',
            'end_time' => '22:00:00',
        ]);
        $this->travelTo(CarbonImmutable::parse('2026-11-09 08:59:00', 'UTC'));

        $response = $this->actingAs($this->admin)
            ->get(route('tasks.index', [
                'search' => 'Refresh visibility fixture',
                'page' => 2,
                'sort' => 'recently_created',
            ]));

        $response->assertOk()
            ->assertSee('name="recurring-task-next-transition"', false)
            ->assertSee('content="1794214800"', false)
            ->assertSee('recurring-task-visibility.js', false);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createSchedule(array $attributes = []): RecurringTask
    {
        $title = $attributes['title'] ?? 'Daily visibility fixture';
        $masterTask = $this->createTask($title, 'recurring');

        return RecurringTask::query()->create(array_replace([
            'task_id' => $masterTask->id,
            'repeat_frequency' => 'daily',
            'start_date' => '2026-11-09',
            'end_date' => '2026-11-13',
            'start_time' => '09:00:00',
            'end_time' => '22:00:00',
            'timezone' => 'UTC',
            'status' => 'active',
        ], $attributes, ['task_id' => $masterTask->id]));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createOccurrence(RecurringTask $schedule, string $date, array $attributes = []): Task
    {
        $localStart = CarbonImmutable::parse($date.' '.$schedule->start_time, $schedule->timezone)->utc();
        $localEnd = CarbonImmutable::parse($date.' '.$schedule->end_time, $schedule->timezone)->utc();

        return $this->createTask(
            $attributes['title'] ?? $schedule->task->title,
            'recurring',
            array_replace([
                'recurring_task_id' => $schedule->id,
                'occurrence_date' => $date,
                'available_from' => $localStart,
                'expires_at' => $localEnd,
            ], $attributes),
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createTask(string $title, string $taskType = 'process', array $attributes = []): Task
    {
        return Task::query()->create(array_replace([
            'task_code' => Task::generateCode(),
            'title' => $title,
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'created_by' => $this->admin->id,
            'task_type' => $taskType,
            'priority' => 'medium',
            'status' => 'assigned',
            'progress_percentage' => 0,
            'expected_output' => 'A recurring visibility test deliverable.',
        ], $attributes));
    }

    public function test_recurring_task_creation_with_client_timezone_is_visible_in_tasks_table(): void
    {
        $employee = $this->createEmployee();
        $this->travelTo(CarbonImmutable::parse('2026-11-10 14:30:00', 'Asia/Kolkata'));

        $response = $this->actingAs($this->admin)->post(route('tasks.store'), [
            'title' => 'Client Timezone Recurring Task',
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'task_type' => 'recurring',
            'priority' => 'medium',
            'repeat_frequency' => 'daily',
            'recurring_start_date' => '2026-11-10',
            'recurring_end_date' => '2026-11-12',
            'recurring_start_time' => '14:00',
            'recurring_end_time' => '16:00',
            'timezone' => 'Asia/Kolkata',
            'expected_output' => 'Expected output test',
            'poc_ids' => [$employee->id],
            'assigned_to' => [$employee->id],
        ]);

        $response->assertRedirect(route('tasks.index'));

        $indexResponse = $this->actingAs($this->admin)->get(route('tasks.index'));
        $indexResponse->assertOk();
        $indexResponse->assertSee('Client Timezone Recurring Task');
    }

    private function createEmployee(): User
    {
        $role = Role::query()->where('name', 'Employee')->firstOrFail();
        $employee = User::factory()->create([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'role_id' => $role->id,
            'status' => 'active',
        ]);
        $employee->assignRole($role);

        return $employee;
    }

    /**
     * @return list<int>
     */
    private function apiTaskIds(string $search): array
    {
        $response = $this->actingAsDesktop($this->admin)
            ->getJson('/api/tasks?search='.urlencode($search).'&per_page=100')
            ->assertOk();

        return collect($response->json('data'))->pluck('id')->all();
    }

    private function actingAsDesktop(User $user): self
    {
        return $this->withToken($user->createToken('recurring-visibility-test')->plainTextToken);
    }
}
