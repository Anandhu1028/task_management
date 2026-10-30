<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Department;
use App\Models\RecurringTask;
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

        foreach (RecurringTask::query()
            ->where('status', 'active')
            ->with(['task.creator:id,name'])
            ->lazyById(100) as $template) {
            $createdCount += $this->generateTemplateOccurrences($template);
        }

        $this->expireOverdueOccurrences();

        return $createdCount;
    }

    public function generateDueOccurrencesForTemplate(RecurringTask $template): int
    {
        return $this->generateTemplateOccurrences($template);
    }

    private function generateTemplateOccurrences(RecurringTask $template): int
    {
        if (! in_array($template->repeat_frequency, RecurringTask::FREQUENCIES, true)) {
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
            ->where('recurring_task_id', $template->id)
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
            $template = RecurringTask::query()
                ->whereKey($templateId)
                ->with(['task.creator', 'task.pocs', 'task.activeAssignees', 'task.attachments'])
                ->lockForUpdate()
                ->first();

            if (! $template || $template->status !== 'active') {
                return false;
            }

            if (Task::withTrashed()
                ->where('recurring_task_id', $template->id)
                ->whereDate('occurrence_date', $occurrenceDate)
                ->exists()) {
                return false;
            }

            $master = $template->task;
            if (! $master) {
                return false;
            }

            $companyIsActive = Company::query()
                ->whereKey($master->company_id)
                ->where('status', 'active')
                ->exists();
            $departmentIsActive = Department::query()
                ->whereKey($master->department_id)
                ->where('company_id', $master->company_id)
                ->where('status', 'active')
                ->exists();

            if (! $companyIsActive || ! $departmentIsActive) {
                Log::warning('Skipped recurring task occurrence because its company or department is inactive.', [
                    'recurring_task_id' => $template->id,
                    'occurrence_date' => $occurrenceDate,
                ]);

                return false;
            }

            $pocIds = $master->pocs->pluck('id')->map(fn (int|string $id): int => (int) $id)->all();

            if ($pocIds === []) {
                Log::warning('Skipped recurring task occurrence because its template has no active department POC.', [
                    'recurring_task_id' => $template->id,
                    'occurrence_date' => $occurrenceDate,
                ]);

                return false;
            }

            $assigneeIds = $master->activeAssignees->pluck('id')->map(fn (int|string $id): int => (int) $id)->all();
            $availableFrom = CarbonImmutable::parse("{$occurrenceDate} {$template->start_time}", $template->timezone)->utc();
            $expiresAt = CarbonImmutable::parse("{$occurrenceDate} {$template->end_time}", $template->timezone)->utc();

            $task = Task::query()->create([
                'task_code' => Task::generateCode(),
                'title' => $master->title,
                'description' => $master->description,
                'company_id' => $master->company_id,
                'department_id' => $master->department_id,
                'created_by' => $master->created_by,
                'assigned_to' => $assigneeIds[0] ?? null,
                'priority' => $master->priority,
                'task_type' => 'recurring',
                'status' => $assigneeIds === [] ? 'notified' : 'assigned',
                'progress_percentage' => 0,
                'start_date' => null,
                'due_date' => $occurrenceDate,
                'original_deadline' => null,
                'estimated_hours' => null,
                'expected_output' => $master->expected_output,
                'recurring_task_id' => $template->id,
                'occurrence_date' => $occurrenceDate,
                'available_from' => $availableFrom,
                'expires_at' => $expiresAt,
            ]);

            $task->pocs()->sync($pocIds);
            $this->assignments->syncTaskAssignees(
                $task,
                $assigneeIds,
                'Recurring task occurrence assignment',
                $master->created_by,
            );
            $task->statusHistory()->create([
                'changed_by' => $master->created_by,
                'new_status' => $task->status,
                'event_type' => 'status',
                'remarks' => "Recurring task occurrence opened for {$occurrenceDate} at ".$availableFrom->setTimezone($template->timezone)->format('g:i A').'.',
            ]);
            $this->attachments->createAttachmentRecords($task, $master->attachments->map(fn ($attachment): array => [
                'path' => $attachment->path, 'original_name' => $attachment->original_name,
                'mime_type' => $attachment->mime_type, 'size_bytes' => $attachment->size_bytes,
            ])->all(), $master->created_by);
            $creator = $master->creator;
            if (! $creator) {
                throw new LogicException("Recurring task template [{$template->id}] has no creator.");
            }

            $this->workflow->notifyTaskCreated($task, $creator);

            return true;
        });
    }

    private function expireOverdueOccurrences(): void
    {
        Task::query()
            ->whereNotNull('recurring_task_id')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->whereNotIn('status', ['completed', 'missed', 'cancelled'])
            ->lazyById(100)
            ->each(function (Task $occurrence): void {
                DB::transaction(function () use ($occurrence): void {
                    $occurrence = Task::query()->lockForUpdate()->find($occurrence->id);

                    if (! $occurrence
                        || $occurrence->expires_at === null
                        || $occurrence->expires_at->isFuture()
                        || in_array($occurrence->status, ['completed', 'missed', 'cancelled'], true)) {
                        return;
                    }

                    $oldStatus = $occurrence->status;
                    $occurrence->update([
                        'status' => 'missed',
                        'missed_at' => $occurrence->expires_at,
                    ]);
                    $occurrence->statusHistory()->create([
                        'changed_by' => $occurrence->created_by,
                        'old_status' => $oldStatus,
                        'new_status' => 'missed',
                        'event_type' => 'missed',
                        'remarks' => 'Recurring occurrence missed when its permitted time window expired.',
                        'created_at' => $occurrence->expires_at,
                        'updated_at' => $occurrence->expires_at,
                    ]);
                });
            });

        RecurringTask::query()
            ->where('status', 'active')
            ->whereDate('end_date', '<', CarbonImmutable::now()->toDateString())
            ->update(['status' => 'completed']);
    }
}
