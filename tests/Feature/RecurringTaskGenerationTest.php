<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\RecurringTask;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RecurringTaskGenerationTest extends TestCase
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

        $this->actingAs($this->admin);
    }

    public function test_recurring_schedule_creation_stores_assignments_and_nullable_task_fields(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-29 08:00:00', 'UTC'));
        Storage::fake('local');
        $poc = $this->createDepartmentUser();
        $assignee = $this->createDepartmentUser();
        $title = 'Daily reconciliations';

        $response = $this->post(route('tasks.store'), [
            'title' => $title,
            'description' => 'Reconcile daily transactions.',
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'task_type' => 'recurring',
            'priority' => 'high',
            'poc_ids' => [$poc->id],
            'assigned_to' => [$assignee->id],
            'repeat_frequency' => 'daily',
            'recurring_start_date' => '2026-10-29',
            'recurring_end_date' => '2026-10-31',
            'recurring_start_time' => '09:00',
            'recurring_end_time' => '11:00',
            'original_deadline' => '2026-10-30',
            'due_date' => '2026-10-30',
            'estimated_hours' => '4.5',
            'expected_output' => 'A reconciled transaction report.',
            'attachments' => [UploadedFile::fake()->createWithContent('requirements.txt', 'reconcile transactions')],
        ]);

        $response->assertRedirect(route('tasks.index'))
            ->assertSessionHas('success', 'Recurring task schedule created successfully.');

        $schedule = RecurringTask::query()->whereHas('task', fn ($query) => $query->where('title', $title))->firstOrFail();
        $this->assertModelExists($schedule);
        $this->assertDatabaseHas('tasks', [
            'id' => $schedule->task_id,
            'task_type' => 'recurring',
            'status' => 'assigned',
        ]);
        $this->assertSame('2026-10-29', $schedule->start_date->toDateString());
        $this->assertSame('2026-10-31', $schedule->end_date->toDateString());
        $this->assertSame([$poc->id], $schedule->task->pocs()->pluck('users.id')->map(fn ($id): int => (int) $id)->all());
        $this->assertSame([$assignee->id], $schedule->task->activeAssignees()->pluck('users.id')->map(fn ($id): int => (int) $id)->all());
        $this->assertSame(0, $schedule->occurrences()->count());
        Storage::disk('local')->assertExists($schedule->task->attachments()->sole()->path);
    }

    public function test_recurring_schedule_creation_generates_a_due_first_occurrence(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-29 10:00:00', 'UTC'));
        $poc = $this->createDepartmentUser();
        $assignee = $this->createDepartmentUser();

        $this->post(route('tasks.store'), $this->recurringPayload($poc, [
            'title' => 'Due daily reconciliation',
            'assigned_to' => [$assignee->id],
            'recurring_start_date' => '2026-10-29',
            'recurring_end_date' => '2026-10-31',
            'recurring_start_time' => '09:00',
            'recurring_end_time' => '11:00',
        ]))->assertRedirect(route('tasks.index'));

        $template = RecurringTaskTemplate::query()->where('title', 'Due daily reconciliation')->firstOrFail();
        $occurrence = $template->occurrences()->sole();

        $this->assertSame('process', $occurrence->task_type);
        $this->assertSame('2026-10-29', $occurrence->occurrence_date->toDateString());
        $this->assertSame($template->id, $occurrence->recurring_task_template_id);
        $this->assertSame('assigned', $occurrence->status);

        $this->get(route('tasks.index'))
            ->assertOk()
            ->assertSee('Recurring')
            ->assertSee('Recurring · Process')
            ->assertDontSee('Recurring Tasks')
            ->assertSee(route('tasks.show', $occurrence), false);
    }

    public function test_recurring_schedule_rejects_invalid_date_and_time_ranges(): void
    {
        $poc = $this->createDepartmentUser();

        $this->post(route('tasks.store'), $this->recurringPayload($poc, [
            'recurring_start_date' => '2026-10-31',
            'recurring_end_date' => '2026-10-29',
            'recurring_start_time' => '11:00',
            'recurring_end_time' => '10:00',
        ]))
            ->assertSessionHasErrors([
                'recurring_end_date' => 'The recurring end date must be on or after the start date.',
                'recurring_end_time' => 'The recurring end time must be later than the start time.',
            ]);

        $this->assertDatabaseCount('recurring_task_templates', 0);
    }

    public function test_recurring_schedule_rejects_assignees_outside_the_active_department(): void
    {
        $poc = $this->createDepartmentUser();
        $inactiveAssignee = $this->createDepartmentUser(['status' => 'inactive']);

        $this->post(route('tasks.store'), $this->recurringPayload($poc, [
            'assigned_to' => [$inactiveAssignee->id],
        ]))
            ->assertSessionHasErrors('assigned_to');

        $this->assertDatabaseCount('recurring_task_templates', 0);
    }

    public function test_generator_catches_up_after_start_time_and_does_not_duplicate_occurrences(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('task-attachments/recurring-template.txt', 'details');
        $this->travelTo(CarbonImmutable::parse('2026-10-31 08:00:00', 'UTC'));
        $template = $this->createRecurringTemplate([
            'start_date' => '2026-10-29',
            'end_date' => '2026-11-02',
            'attachments' => [[
                'path' => 'task-attachments/recurring-template.txt',
                'original_name' => 'recurring-template.txt',
                'mime_type' => 'text/plain',
                'size_bytes' => 7,
            ]],
        ]);

        $this->artisan('tasks:generate-recurring-occurrences')
            ->expectsOutput('Created 2 recurring task occurrence(s).')
            ->assertExitCode(0);

        $occurrences = $template->occurrences()->orderBy('occurrence_date')->get();
        $this->assertCount(2, $occurrences);
        $this->assertSame(['2026-10-29', '2026-10-30'], $occurrences->map(
            fn (Task $task): string => $task->occurrence_date->toDateString(),
        )->all());
        $this->assertDatabaseHas('task_attachments', [
            'task_id' => $occurrences[0]->id,
            'path' => 'task-attachments/recurring-template.txt',
            'uploaded_by' => $this->admin->id,
        ]);

        $occurrences[0]->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);
        $notificationCount = DB::table('notifications')->count();

        $this->artisan('tasks:generate-recurring-occurrences')
            ->expectsOutput('Created 0 recurring task occurrence(s).')
            ->assertExitCode(0);
        $this->assertSame($notificationCount, DB::table('notifications')->count());

        $this->travelTo(CarbonImmutable::parse('2026-10-31 09:00:00', 'UTC'));

        $this->artisan('tasks:generate-recurring-occurrences')
            ->expectsOutput('Created 1 recurring task occurrence(s).')
            ->assertExitCode(0);

        $occurrences = $template->occurrences()->orderBy('occurrence_date')->get();
        $this->assertCount(3, $occurrences);
        $this->assertSame('completed', $occurrences[0]->status);
        $this->assertSame('assigned', $occurrences[1]->status);
        $this->assertSame('assigned', $occurrences[2]->status);
        $this->assertSame('2026-10-31', $occurrences[2]->due_date->toDateString());

        $notificationCount = DB::table('notifications')->count();
        $this->artisan('tasks:generate-recurring-occurrences')
            ->expectsOutput('Created 0 recurring task occurrence(s).')
            ->assertExitCode(0);
        $this->assertSame($notificationCount, DB::table('notifications')->count());
        $this->assertSame(3, $template->occurrences()->count());
    }

    public function test_generator_skips_paused_and_cancelled_schedules(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-31 10:00:00', 'UTC'));
        $template = $this->createRecurringTemplate([
            'start_date' => '2026-10-31',
            'end_date' => '2026-10-31',
            'status' => 'paused',
        ]);

        $this->artisan('tasks:generate-recurring-occurrences')
            ->expectsOutput('Created 0 recurring task occurrence(s).')
            ->assertExitCode(0);

        $template->update(['status' => 'cancelled']);
        $this->artisan('tasks:generate-recurring-occurrences')
            ->expectsOutput('Created 0 recurring task occurrence(s).')
            ->assertExitCode(0);

        $this->assertSame(0, $template->occurrences()->count());
    }

    public function test_task_form_renders_conditional_recurring_schedule_fields(): void
    {
        $response = $this->get(route('tasks.index'));

        $response->assertOk()
            ->assertSee('id="createTaskRecurringSchedule"', false)
            ->assertSee('name="recurring_start_date"', false)
            ->assertSee('name="recurring_end_date"', false)
            ->assertSee('name="recurring_start_time"', false)
            ->assertSee('name="recurring_end_time"', false)
            ->assertSee("taskTypeSelect.value === 'recurring'", false);
    }

    public function test_recurring_templates_are_managed_separately_from_generated_task_instances(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-31 10:00:00', 'UTC'));
        $template = $this->createRecurringTemplate([
            'title' => 'Daily inventory review schedule',
            'start_date' => '2026-10-29',
            'end_date' => '2026-11-02',
        ]);

        $this->artisan('tasks:generate-recurring-occurrences')->assertExitCode(0);

        $occurrence = $template->occurrences()->whereDate('occurrence_date', '2026-10-31')->firstOrFail();

        $this->get(route('tasks.index'))
            ->assertOk()
            ->assertSee('Recurring')
            ->assertSee(route('tasks.show', $occurrence), false);

        $this->get(route('tasks.index', ['task_type' => 'recurring']))
            ->assertOk()
            ->assertSee($occurrence->title)
            ->assertSee(route('tasks.show', $occurrence), false);

        $this->get(route('tasks.index', ['task_type' => 'process']))
            ->assertOk()
            ->assertSee($occurrence->title);

        $this->get(route('tasks.show', $occurrence))
            ->assertOk()
            ->assertSee('Recurring Schedule')
            ->assertSee('Record type')
            ->assertSee('Generated occurrence')
            ->assertSee('Next occurrence')
            ->assertSee('Recurrence status')
            ->assertSee('Schedule control')
            ->assertSee('Template assignees');

        $this->get(route('recurring-tasks.index'))
            ->assertRedirect(route('tasks.index', ['task_type' => 'recurring']));
    }

    public function test_recurring_occurrence_edit_form_does_not_require_estimated_effort(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-31 10:00:00', 'UTC'));
        $template = $this->createRecurringTemplate([
            'start_date' => '2026-10-31',
            'end_date' => '2026-10-31',
        ]);
        $this->artisan('tasks:generate-recurring-occurrences')
            ->expectsOutput('Created 1 recurring task occurrence(s).')
            ->assertExitCode(0);

        $task = $template->occurrences()->firstOrFail();
        $response = $this->get(route('tasks.index', ['edit' => $task->id]));

        $response->assertOk()
            ->assertSee('Occurrence: Oct 31, 2026');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function recurringPayload(User $poc, array $overrides = []): array
    {
        return array_replace([
            'title' => 'Daily report preparation',
            'description' => 'Prepare the report each working day.',
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'task_type' => 'recurring',
            'priority' => 'medium',
            'poc_ids' => [$poc->id],
            'assigned_to' => [],
            'repeat_frequency' => 'daily',
            'recurring_start_date' => '2026-10-29',
            'recurring_end_date' => '2026-10-31',
            'recurring_start_time' => '09:00',
            'recurring_end_time' => '11:00',
            'expected_output' => 'A completed report.',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createRecurringTemplate(array $overrides = []): RecurringTaskTemplate
    {
        $template = RecurringTaskTemplate::query()->create(array_replace([
            'title' => 'Daily inventory review',
            'description' => 'Review the inventory records.',
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'created_by' => $this->admin->id,
            'task_type' => 'process',
            'priority' => 'medium',
            'expected_output' => 'A reviewed inventory report.',
            'original_deadline' => null,
            'estimated_hours' => null,
            'repeat_frequency' => 'daily',
            'start_date' => '2026-10-29',
            'end_date' => '2026-10-31',
            'start_time' => '09:00:00',
            'end_time' => '11:00:00',
            'timezone' => 'UTC',
            'status' => 'active',
            'attachments' => null,
        ], $overrides));

        $poc = $this->createDepartmentUser();
        $assignee = $this->createDepartmentUser();
        $template->pocs()->sync([$poc->id]);
        $template->assignees()->sync([$assignee->id]);

        return $template;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createDepartmentUser(array $attributes = []): User
    {
        return User::factory()->create(array_replace([
            'company_id' => $this->company->id,
            'department_id' => $this->department->id,
            'status' => 'active',
        ], $attributes));
    }
}
