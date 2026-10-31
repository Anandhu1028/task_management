<?php

namespace App\Services;

use App\Models\DailyAccountabilityEvent;
use App\Models\DailyAccountabilityExemption;
use App\Models\DailyAccountabilitySetting;
use App\Models\DailyTask;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TaskStatusHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DailyTaskAccountabilityService
{
    public function recordDailyTaskActivity(DailyTask $dailyTask, TaskStatusHistory $history): void
    {
        DB::transaction(fn () => $this->recordDailyTaskActivityInTransaction($dailyTask, $history));
    }

    public function applyExemptionToDueReports(DailyAccountabilityExemption $exemption): int
    {
        if (! $exemption->active) {
            return 0;
        }

        $settings = DailyAccountabilitySetting::current();
        $now = now()->timezone(config('app.timezone'));
        $processed = 0;

        DailyTask::query()
            ->whereDate('work_date', '>=', $exemption->starts_on->toDateString())
            ->whereDate('work_date', '<=', $exemption->ends_on->toDateString())
            ->where('created_at', '<=', $now)
            ->where('status', '!=', 'cancelled')
            ->whereHas('task')
            ->chunkById(250, function ($dailyTasks) use ($exemption, $settings, $now, &$processed): void {
                foreach ($dailyTasks as $dailyTask) {
                    $reportDate = $dailyTask->work_date?->toDateString();
                    if ($reportDate === null) {
                        continue;
                    }

                    $deadline = $this->deadline($reportDate, $settings);
                    if ($deadline->gt($now)
                        || $dailyTask->created_at?->gt($deadline)
                        || ! $this->isWorkingDay($deadline, $settings)) {
                        continue;
                    }

                    $wasProcessed = DB::transaction(function () use ($dailyTask, $deadline, $exemption, $reportDate): bool {
                        $employeeId = $this->assigneeAt($dailyTask, $deadline);
                        if ($employeeId === null
                            || ($exemption->employee_id !== null && $employeeId !== $exemption->employee_id)) {
                            return false;
                        }

                        $this->applyExemption($dailyTask, $employeeId, $reportDate);

                        return true;
                    });

                    if ($wasProcessed) {
                        $processed++;
                    }
                }
            });

        $this->applyExemptionToEmptyTaskDays($exemption);

        return $processed;
    }

    private function recordDailyTaskActivityInTransaction(DailyTask $dailyTask, TaskStatusHistory $history): void
    {
        $reportDate = $history->activity_date?->toDateString()
            ?? $dailyTask->work_date?->toDateString();

        if ($reportDate === null) {
            return;
        }

        $settings = DailyAccountabilitySetting::current();
        $deadline = $this->deadline($reportDate, $settings);
        $now = now()->timezone(config('app.timezone'));

        if ($deadline->gt($now) || $dailyTask->created_at?->gt($deadline)) {
            return;
        }

        $employeeId = $this->assigneeAt($dailyTask, $deadline)
            ?? ($history->changed_by === $dailyTask->assigned_to ? (int) $history->changed_by : null);

        if ($employeeId === null || ! $this->isWorkingDay($deadline, $settings)) {
            return;
        }

        $exemption = $this->activeExemption($employeeId, $reportDate);
        if ($exemption !== null) {
            $this->applyExemption($dailyTask, $employeeId, $reportDate);

            return;
        }

        if ($this->submissionBeforeDeadline($dailyTask, $reportDate, $deadline) === null) {
            $this->recordMissing($dailyTask, $employeeId, $reportDate);
        }
    }

    public function processDueReports(): int
    {
        $settings = DailyAccountabilitySetting::current();
        $now = now()->timezone(config('app.timezone'));
        $processed = 0;

        DailyTask::query()
            ->whereDate('work_date', '<=', $now->toDateString())
            ->where('created_at', '<=', $now)
            ->where('status', '!=', 'cancelled')
            ->whereHas('task')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('daily_accountability_events')
                    ->whereColumn('daily_accountability_events.daily_task_id', 'daily_tasks.id')
                    ->whereColumn('daily_accountability_events.report_date', 'daily_tasks.work_date')
                    ->where('daily_accountability_events.event_type', 'missed');
            })
            ->orderBy('work_date')
            ->orderBy('id')
            ->chunk(250, function ($dailyTasks) use ($settings, $now, &$processed): void {
                foreach ($dailyTasks as $dailyTask) {
                    $wasProcessed = DB::transaction(function () use ($dailyTask, $now, $settings): bool {
                        $reportDate = $dailyTask->work_date?->toDateString();
                        if ($reportDate === null) {
                            return false;
                        }

                        $deadline = $this->deadline($reportDate, $settings);
                        if ($deadline->gt($now)
                            || $dailyTask->created_at?->gt($deadline)
                            || ! $this->isWorkingDay($deadline, $settings)) {
                            return false;
                        }

                        $employeeId = $this->assigneeAt($dailyTask, $deadline);
                        if ($employeeId === null) {
                            return false;
                        }

                        $exemption = $this->activeExemption($employeeId, $reportDate);
                        if ($exemption !== null) {
                            $this->applyExemption($dailyTask, $employeeId, $reportDate);

                            return true;
                        }

                        if ($this->submissionBeforeDeadline($dailyTask, $reportDate, $deadline) === null) {
                            $this->recordMissing($dailyTask, $employeeId, $reportDate);
                        }

                        return true;
                    });

                    if ($wasProcessed) {
                        $processed++;
                    }
                }
            });

        return $processed + $this->processUncoveredAssignmentDays($now, $settings);
    }

    private function processUncoveredAssignmentDays(Carbon $now, DailyAccountabilitySetting $settings): int
    {
        $processed = 0;

        Task::query()
            ->where(function (Builder $query): void {
                $query->whereNotNull('assigned_to')
                    ->orWhereHas('assignments');
            })
            ->where('created_at', '<=', $now)
            ->with([
                'assignments',
                'statusHistory' => fn (HasMany $query) => $query
                    ->where(fn (Builder $historyQuery) => $historyQuery
                        ->whereIn('event_type', ['hold', 'resume', 'cancellation'])
                        ->orWhereIn('new_status', ['on_hold', 'completed', 'cancelled'])),
                'dailyTasks.activity' => fn (HasMany $query) => $query
                    ->whereIn('event_type', ['daily_task_assigned', 'daily_task_reassigned', 'daily_task_rescheduled']),
            ])
            ->chunkById(100, function ($tasks) use ($now, $settings, &$processed): void {
                $recordedDayKeys = DailyAccountabilityEvent::query()
                    ->whereIn('task_id', $tasks->modelKeys())
                    ->whereNull('daily_task_id')
                    ->where('event_type', 'missed')
                    ->get(['task_id', 'employee_id', 'report_date'])
                    ->mapWithKeys(fn (DailyAccountabilityEvent $event): array => [
                        $event->task_id.'|'.$event->employee_id.'|'.$event->report_date?->toDateString() => true,
                    ])
                    ->all();

                foreach ($tasks as $task) {
                    $assignments = $task->assignments;
                    if ($assignments->isEmpty() && $task->assigned_to !== null) {
                        $assignments = collect([
                            new TaskAssignment([
                                'user_id' => $task->assigned_to,
                                'assigned_at' => $task->created_at,
                            ]),
                        ]);
                    }

                    foreach ($assignments as $assignment) {
                        $employeeId = (int) $assignment->user_id;
                        $assignedAt = $assignment->assigned_at?->timezone(config('app.timezone'));
                        if ($employeeId === 0 || $assignedAt === null) {
                            continue;
                        }

                        $firstDate = $assignedAt->copy()->startOfDay();
                        if ($task->start_date !== null && $task->start_date->gt($firstDate)) {
                            $firstDate = $task->start_date->copy()->startOfDay();
                        }

                        for ($reportDate = $firstDate; $reportDate->lte($now); $reportDate->addDay()) {
                            $date = $reportDate->toDateString();
                            $recordKey = $task->id.'|'.$employeeId.'|'.$date;
                            if (isset($recordedDayKeys[$recordKey])) {
                                continue;
                            }

                            $deadline = $this->deadline($date, $settings);
                            $unassignedAt = $assignment->unassigned_at?->timezone(config('app.timezone'));

                            if (
                                $deadline->gt($now)
                                || $assignedAt->gt($deadline)
                                || ($unassignedAt !== null && $unassignedAt->lt($deadline))
                                || ! $this->isWorkingDay($deadline, $settings)
                                || ! $this->isTaskExpectedAt($task, $deadline)
                            ) {
                                continue;
                            }

                            $wasProcessed = DB::transaction(function () use ($task, $employeeId, $date, $deadline): bool {
                                if ($this->hasDailyTaskForEmployeeOnDate($task, $employeeId, $date, $deadline)) {
                                    return false;
                                }

                                $exemption = $this->activeExemption($employeeId, $date);
                                if ($exemption !== null) {
                                    $this->applyEmptyTaskDayExemption($task, $employeeId, $date);

                                    return true;
                                }

                                $this->recordEmptyTaskDayMiss($task, $employeeId, $date);

                                return true;
                            });

                            if ($wasProcessed) {
                                $recordedDayKeys[$recordKey] = true;
                                $processed++;
                            }
                        }
                    }
                }
            });

        return $processed;
    }

    private function isTaskExpectedAt(Task $task, Carbon $deadline): bool
    {
        $histories = $task->statusHistory->sortBy(
            fn (TaskStatusHistory $history): int => $history->created_at?->getTimestamp() ?? 0,
        );
        $terminalHistory = $histories->first(
            fn (TaskStatusHistory $history): bool => in_array($history->new_status, ['completed', 'cancelled'], true)
                || $history->event_type === 'cancellation',
        );
        $terminalAt = $task->completed_at
            ?? $task->cancelled_at
            ?? $terminalHistory?->created_at
            ?? (in_array($task->status, ['completed', 'cancelled'], true) ? $task->updated_at : null);

        if ($terminalAt !== null && $terminalAt->lte($deadline)) {
            return false;
        }

        $held = false;
        foreach ($histories as $history) {
            if ($history->created_at?->gt($deadline)) {
                break;
            }

            if ($history->event_type === 'hold' || $history->new_status === 'on_hold') {
                $held = true;
            } elseif ($history->event_type === 'resume' || $history->old_status === 'on_hold') {
                $held = false;
            }
        }

        if ($histories->isEmpty() && $task->status === 'on_hold') {
            $held = true;
        }

        return ! $held;
    }

    private function hasDailyTaskForEmployeeOnDate(Task $task, int $employeeId, string $reportDate, Carbon $deadline): bool
    {
        return $task->dailyTasks->contains(function (DailyTask $dailyTask) use ($employeeId, $reportDate, $deadline): bool {
            if (
                $dailyTask->status === 'cancelled'
                || $dailyTask->created_at?->gt($deadline)
                || $this->assigneeAt($dailyTask, $deadline) !== $employeeId
            ) {
                return false;
            }

            if ($dailyTask->work_date?->toDateString() === $reportDate) {
                return true;
            }

            return $dailyTask->activity->contains(fn (TaskStatusHistory $history): bool => $history->event_type === 'daily_task_rescheduled'
                && $history->created_at?->lte($deadline)
                && str_starts_with((string) $history->old_status, $reportDate.' '));
        });
    }

    private function recordEmptyTaskDayMiss(Task $task, int $employeeId, string $reportDate): void
    {
        $this->recordTaskDayEvent(
            $task->id,
            null,
            'No Daily Task Created',
            $employeeId,
            $reportDate,
            'missed',
            'No Daily Task Created. No Daily Update Submitted. No Work Progress Recorded by the cutoff.',
            'missed-day|'.$task->id.'|'.$employeeId.'|'.$reportDate,
        );
    }

    private function applyEmptyTaskDayExemption(Task $task, int $employeeId, string $reportDate): void
    {
        DailyAccountabilityEvent::query()
            ->where('task_id', $task->id)
            ->whereNull('daily_task_id')
            ->where('employee_id', $employeeId)
            ->whereDate('report_date', $reportDate)
            ->where('event_type', 'missed')
            ->delete();
    }

    private function applyExemptionToEmptyTaskDays(DailyAccountabilityExemption $exemption): void
    {
        $events = DailyAccountabilityEvent::query()
            ->with('task')
            ->whereNull('daily_task_id')
            ->where('event_type', 'missed')
            ->whereDate('report_date', '>=', $exemption->starts_on->toDateString())
            ->whereDate('report_date', '<=', $exemption->ends_on->toDateString())
            ->when(
                $exemption->employee_id !== null,
                fn (Builder $query) => $query->where('employee_id', $exemption->employee_id),
            )
            ->get();

        foreach ($events as $event) {
            if ($event->task === null || $event->employee_id === null || $event->report_date === null) {
                continue;
            }

            DB::transaction(fn () => $this->applyEmptyTaskDayExemption(
                $event->task,
                $event->employee_id,
                $event->report_date->toDateString(),
            ));
        }
    }

    private function recordMissing(DailyTask $dailyTask, int $employeeId, string $reportDate): void
    {
        $this->recordEvent(
            $dailyTask,
            $employeeId,
            $reportDate,
            'missed',
            'No Daily Update was submitted by the cutoff.',
            'missed|'.$dailyTask->id.'|'.$reportDate,
        );
    }

    private function applyExemption(
        DailyTask $dailyTask,
        int $employeeId,
        string $reportDate,
    ): void {
        DailyAccountabilityEvent::query()
            ->where('task_id', $dailyTask->task_id)
            ->where('daily_task_id', $dailyTask->id)
            ->where('employee_id', $employeeId)
            ->whereDate('report_date', $reportDate)
            ->where('event_type', 'missed')
            ->delete();
    }

    private function recordEvent(
        DailyTask $dailyTask,
        int $employeeId,
        string $reportDate,
        string $eventType,
        string $description,
        string $eventKey,
        ?int $sourceHistoryId = null,
    ): DailyAccountabilityEvent {
        return $this->recordTaskDayEvent(
            $dailyTask->task_id,
            $dailyTask->id,
            $dailyTask->title,
            $employeeId,
            $reportDate,
            $eventType,
            $description,
            $eventKey,
            $sourceHistoryId,
        );
    }

    private function recordTaskDayEvent(
        int $taskId,
        ?int $dailyTaskId,
        string $dailyTaskTitle,
        int $employeeId,
        string $reportDate,
        string $eventType,
        string $description,
        string $eventKey,
        ?int $sourceHistoryId = null,
    ): DailyAccountabilityEvent {
        $employeeName = User::query()->withTrashed()->find($employeeId)?->name ?? 'Former employee';
        $taskTitle = Task::query()->withTrashed()->find($taskId)?->title ?? 'Former task';
        $now = now();

        DB::table('daily_accountability_events')->insertOrIgnore([
            'task_id' => $taskId,
            'daily_task_id' => $dailyTaskId,
            'employee_id' => $employeeId,
            'employee_name' => $employeeName,
            'task_title' => $taskTitle,
            'daily_task_title' => $dailyTaskTitle,
            'source_history_id' => $sourceHistoryId,
            'event_type' => $eventType,
            'report_date' => $reportDate,
            'description' => $description,
            'event_key' => $eventKey,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return DailyAccountabilityEvent::query()->where('event_key', $eventKey)->firstOrFail();
    }

    private function activeExemption(int $employeeId, string $reportDate): ?DailyAccountabilityExemption
    {
        return DailyAccountabilityExemption::query()
            ->where('active', true)
            ->whereDate('starts_on', '<=', $reportDate)
            ->whereDate('ends_on', '>=', $reportDate)
            ->where(fn (Builder $query) => $query
                ->where('employee_id', $employeeId)
                ->orWhereNull('employee_id'))
            ->orderByDesc('employee_id')
            ->first();
    }

    private function submissionBeforeDeadline(
        DailyTask $dailyTask,
        string $reportDate,
        Carbon $deadline,
    ): ?TaskStatusHistory {
        return TaskStatusHistory::query()
            ->where('daily_task_id', $dailyTask->id)
            ->whereIn('event_type', ['daily_task_update_submitted', 'daily_task_completed'])
            ->where('created_at', '<=', $deadline)
            ->where(function (Builder $query) use ($reportDate): void {
                $query->whereDate('activity_date', $reportDate)
                    ->orWhere(fn (Builder $legacyQuery) => $legacyQuery
                        ->whereNull('activity_date')
                        ->whereDate('created_at', $reportDate));
            })
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();
    }

    private function assigneeAt(DailyTask $dailyTask, Carbon $at): ?int
    {
        $assignment = $dailyTask->relationLoaded('activity')
            ? $dailyTask->activity
                ->filter(fn (TaskStatusHistory $history): bool => in_array(
                    $history->event_type,
                    ['daily_task_assigned', 'daily_task_reassigned'],
                    true,
                ) && $history->created_at?->lte($at))
                ->sort(fn (TaskStatusHistory $first, TaskStatusHistory $second): int => ($first->created_at?->timestamp ?? 0)
                    <=> ($second->created_at?->timestamp ?? 0)
                    ?: $first->id <=> $second->id)
                ->last()
            : TaskStatusHistory::query()
                ->where('daily_task_id', $dailyTask->id)
                ->whereIn('event_type', ['daily_task_assigned', 'daily_task_reassigned'])
                ->where('created_at', '<=', $at)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first();

        if ($assignment !== null && ctype_digit((string) $assignment->new_status)) {
            return (int) $assignment->new_status;
        }

        return $dailyTask->created_at?->lte($at) ? (int) $dailyTask->assigned_to : null;
    }

    private function deadline(string $reportDate, DailyAccountabilitySetting $settings): Carbon
    {
        return Carbon::parse($reportDate, config('app.timezone'))
            ->setTimeFromTimeString($settings->cutoff_time);
    }

    private function isWorkingDay(Carbon $date, DailyAccountabilitySetting $settings): bool
    {
        return in_array(strtolower($date->format('l')), $settings->working_days, true);
    }
}
