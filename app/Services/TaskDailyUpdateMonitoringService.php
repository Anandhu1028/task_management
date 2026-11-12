<?php

namespace App\Services;

use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class TaskDailyUpdateMonitoringService
{
    public function __construct(private readonly WorkingDayCalculator $workingDays) {}

    public function withTodayUpdateFlags(Builder $query, string $date): Builder
    {
        $dayStartsAt = CarbonImmutable::parse($date, config('app.timezone'))->startOfDay();
        $dayEndsAt = $dayStartsAt->addDay();

        return $query->withExists([
            'subtasks as has_undated_subtask_today' => fn (Builder $subtasks): Builder => $subtasks
                ->where('subtasks.created_at', '>=', $dayStartsAt)
                ->where('subtasks.created_at', '<', $dayEndsAt)
                ->whereDoesntHave('dailyUpdates')
                ->whereDoesntHave('volumeUpdates'),
            'subtaskDailyUpdates as has_subtask_daily_update_today' => fn (Builder $updates): Builder => $updates
                ->where('work_date', $date)
                ->whereNotNull('subtask_id'),
            'volumeUpdates as has_volume_subtask_update_today' => fn (Builder $updates): Builder => $updates
                ->where('update_date', $date)
                ->whereNotNull('subtask_id'),
        ]);
    }

    public function todayStatus(Task $task, string $date): ?string
    {
        if (! $this->isEligibleForMonitoring($task) || ! $this->isExpectedDate($task, $date)) {
            return null;
        }

        return $this->hasUpdateOnDate($task, $date) ? 'submitted' : 'missing';
    }

    private function isEligibleForMonitoring(Task $task): bool
    {
        if (! in_array($task->status, ['assigned', 'in_progress', 'rework'], true)) {
            return false;
        }

        return $task->task_type !== 'recurring'
            || ($task->recurring_task_id !== null && $task->occurrence_date !== null);
    }

    private function isExpectedDate(Task $task, string $date): bool
    {
        $dateRange = $this->dateRange($task);
        if ($dateRange === null) {
            return false;
        }

        [$startDate, $deadlineDate] = $dateRange;
        $checkDate = CarbonImmutable::parse($date, config('app.timezone'))->startOfDay();

        return $checkDate->betweenIncluded($startDate, $deadlineDate)
            && $this->workingDays->dates($checkDate, $checkDate) !== [];
    }

    /**
     * @return array{CarbonImmutable, CarbonImmutable}|null
     */
    private function dateRange(Task $task): ?array
    {
        $timezone = config('app.timezone');
        if ($task->task_type === 'recurring') {
            if ($task->recurring_task_id === null || $task->occurrence_date === null) {
                return null;
            }

            $start = CarbonImmutable::parse($task->occurrence_date->toDateString(), $timezone)->startOfDay();

            return [$start, $start];
        }

        $startDate = $task->task_type === 'volume'
            ? ($task->volume?->start_date ?? $task->start_date)
            : $task->start_date;
        $startDate ??= $task->created_at?->timezone($timezone)->toDateString();
        $deadlineDate = $task->task_type === 'volume'
            ? ($task->volume?->deadline_date ?? $task->due_date ?? $task->original_deadline)
            : ($task->due_date ?? $task->original_deadline);
        $deadlineDate ??= today($timezone)->toDateString();

        if ($startDate === null) {
            return null;
        }

        $start = CarbonImmutable::parse($startDate, $timezone)->startOfDay();
        $deadline = CarbonImmutable::parse($deadlineDate, $timezone)->startOfDay();

        return $start->gt($deadline) ? null : [$start, $deadline];
    }

    private function hasUpdateOnDate(Task $task, string $date): bool
    {
        if ($task->task_type === 'volume') {
            return (bool) ($task->has_volume_subtask_update_today ?? false)
                || (bool) ($task->has_undated_subtask_today ?? false);
        }

        return (bool) ($task->has_subtask_daily_update_today ?? false)
            || (bool) ($task->has_undated_subtask_today ?? false);
    }
}
