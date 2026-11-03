<?php

namespace App\Observers;

use App\Models\TaskStatusHistory;
use App\Services\EmployeeDailyAccountabilityService;

class TaskStatusHistoryObserver
{
    public function __construct(private readonly EmployeeDailyAccountabilityService $accountability) {}

    public function created(TaskStatusHistory $history): void
    {
        $employee = $history->changedBy;
        if ($employee === null || ! $this->isMeaningfulActivity($history->event_type)) {
            return;
        }

        $activityDate = $history->activity_date?->toDateString()
            ?? $history->created_at?->timezone(config('app.timezone'))->toDateString()
            ?? today()->toDateString();

        $this->accountability->recordActivity(
            $employee,
            $activityDate,
            $history->created_at,
        );
    }

    private function isMeaningfulActivity(string $eventType): bool
    {
        return in_array($eventType, [
            'status',
            'accepted',
            'start',
            'hold',
            'resume',
            'work_update',
            'submission',
            'review',
        ], true) || str_starts_with($eventType, 'subtask_');
    }
}
