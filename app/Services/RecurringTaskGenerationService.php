<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Department;
use App\Models\RecurringTaskTemplate;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;

class RecurringTaskGenerationService
{
    public function __construct(
        private readonly TaskAssignmentService $assignments,
        private readonly TaskAttachmentService $attachments,
        private readonly TaskWorkflowService $workflow,
    ) {}

    public function generateDueOccurrences(): int
    {
        $createdCount = 0;

        foreach (RecurringTaskTemplate::query()
            ->where('status', 'active')
            ->with(['creator:id,name'])
            ->lazyById(100) as $template) {
            $createdCount += $this->generateTemplateOccurrences($template);
        }

        return $createdCount;
    }

    public function generateDueOccurrencesForTemplate(RecurringTaskTemplate $template): int
    {
        return $this->generateTemplateOccurrences($template);
    }

    private function generateTemplateOccurrences(RecurringTaskTemplate $template): int
    {
        if (! in_array($template->repeat_frequency, RecurringTaskTemplate::FREQUENCIES, true)) {
            throw new LogicException("Unsupported recurring frequency [{$template->repeat_frequency}].");
        }

        $timezone = $template->timezone;
        $now = CarbonImmutable::now($timezone);
        $startDate = CarbonImmutable::parse($template->start_date->toDateString(), $timezone);
        $endDate = CarbonImmutable::parse($template->end_date->toDateString(), $timezone);
        $today = $now->startOfDay();
        $lastEligibleDate = $endDate->lessThan($today) ? $endDate : $today;

        if ($lastEligibleDate->equalTo($today) && $now->format('H:i:s') < $template->start_time) {
            $lastEligibleDate = $today->subDay();
        }

        if ($startDate->greaterThan($lastEligibleDate)) {
            return 0;
        }

        $existingDates = Task::withTrashed()
            ->where('recurring_task_template_id', $template->id)
            ->whereBetween('occurrence_date', [$startDate->toDateString(), $lastEligibleDate->toDateString()])
            ->pluck('occurrence_date')
            ->mapWithKeys(fn (string $date): array => [$date => true])
            ->all();

        $createdCount = 0;
        for ($occurrenceDate = $startDate; $occurrenceDate->lessThanOrEqualTo($lastEligibleDate); $occurrenceDate = $this->nextOccurrenceDate($occurrenceDate, $template->repeat_frequency)) {
            $date = $occurrenceDate->toDateString();
            if (isset($existingDates[$date])) {
                continue;
            }

            if ($this->createOccurrence($template->id, $date)) {
                $createdCount++;
            }
        }

        return $createdCount;
    }

    private function nextOccurrenceDate(CarbonImmutable $occurrenceDate, string $frequency): CarbonImmutable
    {
        return match ($frequency) {
            'weekly' => $occurrenceDate->addWeek(),
            'monthly' => $occurrenceDate->addMonthNoOverflow(),
            default => $occurrenceDate->addDay(),
        };
    }

    private function createOccurrence(int $templateId, string $occurrenceDate): bool
    {
        return DB::transaction(function () use ($templateId, $occurrenceDate): bool {
            $template = RecurringTaskTemplate::query()
                ->whereKey($templateId)
                ->lockForUpdate()
                ->first();

            if (! $template || $template->status !== 'active') {
                return false;
            }

            if (Task::withTrashed()
                ->where('recurring_task_template_id', $template->id)
                ->whereDate('occurrence_date', $occurrenceDate)
                ->exists()) {
                return false;
            }

            $companyIsActive = Company::query()
                ->whereKey($template->company_id)
                ->where('status', 'active')
                ->exists();
            $departmentIsActive = Department::query()
                ->whereKey($template->department_id)
                ->where('company_id', $template->company_id)
                ->where('status', 'active')
                ->exists();

            if (! $companyIsActive || ! $departmentIsActive) {
                Log::warning('Skipped recurring task occurrence because its company or department is inactive.', [
                    'recurring_task_template_id' => $template->id,
                    'occurrence_date' => $occurrenceDate,
                ]);

                return false;
            }

            $pocIds = $template->pocs()
                ->where('users.company_id', $template->company_id)
                ->where('users.department_id', $template->department_id)
                ->where('users.status', 'active')
                ->pluck('users.id')
                ->map(fn (int|string $id): int => (int) $id)
                ->all();

            if ($pocIds === []) {
                Log::warning('Skipped recurring task occurrence because its template has no active department POC.', [
                    'recurring_task_template_id' => $template->id,
                    'occurrence_date' => $occurrenceDate,
                ]);

                return false;
            }

            $assigneeIds = $template->assignees()
                ->where('users.company_id', $template->company_id)
                ->where('users.department_id', $template->department_id)
                ->where('users.status', 'active')
                ->pluck('users.id')
                ->map(fn (int|string $id): int => (int) $id)
                ->all();

            $task = Task::query()->create([
                'task_code' => Task::generateCode(),
                'title' => $template->title,
                'description' => $template->description,
                'company_id' => $template->company_id,
                'department_id' => $template->department_id,
                'created_by' => $template->created_by,
                'assigned_to' => $assigneeIds[0] ?? null,
                'priority' => $template->priority,
                'task_type' => $template->task_type,
                'status' => $assigneeIds === [] ? 'notified' : 'assigned',
                'progress_percentage' => 0,
                'start_date' => null,
                'due_date' => $occurrenceDate,
                'original_deadline' => null,
                'estimated_hours' => null,
                'expected_output' => $template->expected_output,
                'recurring_task_template_id' => $template->id,
                'occurrence_date' => $occurrenceDate,
            ]);

            $task->pocs()->sync($pocIds);
            $this->assignments->syncTaskAssignees(
                $task,
                $assigneeIds,
                'Recurring task occurrence assignment',
                $template->created_by,
            );
            $task->statusHistory()->create([
                'changed_by' => $template->created_by,
                'new_status' => $task->status,
                'event_type' => 'status',
                'remarks' => "Recurring task occurrence for {$occurrenceDate}.",
            ]);
            $this->attachments->createAttachmentRecords(
                $task,
                $template->attachments ?? [],
                $template->created_by,
            );
            $creator = $template->creator;
            if (! $creator) {
                throw new LogicException("Recurring task template [{$template->id}] has no creator.");
            }

            $this->workflow->notifyTaskCreated($task, $creator);

            return true;
        });
    }
}
