<?php

namespace App\Services;

use App\Models\DailyTask;
use App\Models\TaskStatusHistory;
use App\Models\User;

class DailyTaskActivityService
{
    public function record(
        DailyTask $dailyTask,
        ?User $actor,
        string $eventType,
        ?string $oldValue,
        ?string $newValue,
        ?string $remarks = null,
    ): TaskStatusHistory {
        return TaskStatusHistory::query()->create([
            'task_id' => $dailyTask->task_id,
            'subtask_id' => $dailyTask->subtask_id,
            'daily_task_id' => $dailyTask->id,
            'changed_by' => $actor?->id,
            'old_status' => $oldValue === null ? null : mb_substr($oldValue, 0, 255),
            'new_status' => mb_substr($newValue ?? 'Updated', 0, 255),
            'event_type' => $eventType,
            'remarks' => $remarks,
        ]);
    }
}
