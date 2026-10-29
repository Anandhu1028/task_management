<?php

namespace App\Services;

use App\Models\RecurringTask;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RecurringTaskTemplateService
{
    public function __construct(
        private readonly RecurringTaskGenerationService $generation,
        private readonly TaskAssignmentService $assignments,
        private readonly TaskAttachmentService $attachments,
    ) {}

    /**
     * @param  array<int, int|string>  $pocIds
     * @param  array<int, int|string>  $assigneeIds
     * @param  list<array{path: string, original_name: string, mime_type: ?string, size_bytes: int}>  $attachments
     * @param  array<string, mixed>  $data
     */
    public function create(
        array $data,
        array $pocIds,
        array $assigneeIds,
        array $attachments,
        User $creator,
    ): RecurringTask {
        return DB::transaction(function () use ($data, $pocIds, $assigneeIds, $attachments, $creator): RecurringTask {
            $task = Task::query()->create([
                'task_code' => Task::generateCode(),
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'company_id' => $data['company_id'],
                'department_id' => $data['department_id'],
                'created_by' => $creator->id,
                'assigned_to' => $assigneeIds[0] ?? null,
                'task_type' => 'recurring',
                'priority' => $data['priority'],
                'expected_output' => $data['expected_output'],
                'status' => $assigneeIds === [] ? 'notified' : 'assigned',
                'progress_percentage' => 0,
            ]);

            $task->pocs()->sync($pocIds);
            $this->assignments->syncTaskAssignees($task, $assigneeIds, 'Initial recurring schedule assignment', $creator->id);
            $this->attachments->createAttachmentRecords($task, $attachments, $creator->id);
            $task->statusHistory()->create([
                'changed_by' => $creator->id,
                'new_status' => $task->status,
                'event_type' => 'status',
                'remarks' => 'Recurring schedule created.',
            ]);

            $schedule = RecurringTask::query()->create([
                'task_id' => $task->id,
                'repeat_frequency' => $data['repeat_frequency'],
                'start_date' => $data['recurring_start_date'],
                'end_date' => $data['recurring_end_date'],
                'start_time' => $data['recurring_start_time'],
                'end_time' => $data['recurring_end_time'],
                'timezone' => config('app.timezone'),
                'status' => 'active',
            ]);

            $this->generation->generateDueOccurrencesForTemplate($schedule);

            return $schedule;
        });
    }
}
