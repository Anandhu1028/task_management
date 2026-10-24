<?php

namespace App\Services;

use App\Models\Subtask;
use App\Models\Task;
use App\Models\TaskStatusHistory;
use App\Models\User;
use App\Notifications\SubtaskNotification;
use Illuminate\Support\Collection;

class SubtaskActivityService
{
    public function record(
        Task $task,
        ?Subtask $subtask,
        User $actor,
        string $event,
        ?string $oldValue,
        ?string $newValue,
        string $remarks,
    ): TaskStatusHistory {
        return TaskStatusHistory::query()->create([
            'task_id' => $task->id,
            'subtask_id' => $subtask?->id,
            'changed_by' => $actor->id,
            'old_status' => $oldValue === null ? null : mb_substr($oldValue, 0, 255),
            'new_status' => $newValue === null ? 'Updated' : mb_substr($newValue, 0, 255),
            'event_type' => 'subtask_'.$event,
            'remarks' => $remarks,
        ]);
    }

    public function notifyParticipants(
        Task $task,
        Subtask $subtask,
        ?User $actor,
        string $event,
        string $title,
        string $message,
        ?string $deduplicationKey = null,
    ): void {
        $task->loadMissing('activeAssignees');

        $recipientIds = collect([
            $task->created_by,
            $task->assigned_to,
            ...$task->activeAssignees->modelKeys(),
            $subtask->assigned_to,
        ])
            ->filter()
            ->map(fn (int|string $id): int => (int) $id)
            ->reject(fn (int $id): bool => $actor !== null && $id === (int) $actor->id)
            ->unique()
            ->values();

        if ($recipientIds->isEmpty()) {
            return;
        }

        User::query()
            ->where('status', 'active')
            ->whereIn('id', $recipientIds)
            ->each(function (User $recipient) use ($task, $subtask, $actor, $event, $title, $message, $deduplicationKey): void {
                if ($deduplicationKey !== null && $recipient->notifications()
                    ->where('data->subtask_id', $subtask->id)
                    ->where('data->event', $event)
                    ->where('data->deduplication_key', $deduplicationKey)
                    ->exists()) {
                    return;
                }

                $recipient->notify(new SubtaskNotification(
                    $task,
                    $subtask,
                    $actor,
                    $event,
                    $title,
                    $message,
                    $deduplicationKey,
                ));
            });
    }

    /**
     * @param  Collection<int, Subtask>  $conflicts
     */
    public function recordParentDeadlineConflicts(
        Task $task,
        Collection $conflicts,
        User $actor,
        ?string $oldDeadline,
        ?string $newDeadline,
        ?string $oldStartDate,
        ?string $newStartDate,
    ): void {
        foreach ($conflicts as $subtask) {
            $this->record(
                $task,
                $subtask,
                $actor,
                'conflict',
                'start: '.($oldStartDate ?? 'none').'; due: '.($oldDeadline ?? 'none'),
                'start: '.($newStartDate ?? 'none').'; due: '.($newDeadline ?? 'none'),
                "Blocked a parent timeframe change because subtask \"{$subtask->title}\" conflicts with the requested dates.",
            );
            $this->notifyParticipants(
                $task,
                $subtask,
                $actor,
                'parent_deadline_conflict',
                'Subtask timeframe conflict',
                "{$actor->name} attempted to change the timeframe for {$task->task_code}, but {$subtask->title} would fall outside the main task dates."
                    .' Requested deadline: '.($newDeadline ?? 'none')
                    .'; requested start: '.($newStartDate ?? 'none').'.',
            );
        }
    }
}
