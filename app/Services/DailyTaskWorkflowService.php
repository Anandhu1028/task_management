<?php

namespace App\Services;

use App\Models\DailyTask;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class DailyTaskWorkflowService
{
    public function __construct(private readonly DailyTaskActivityService $activity) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Task $task, User $actor, array $data): DailyTask
    {
        Gate::forUser($actor)->authorize('createDailyTask', $task);

        return DB::transaction(function () use ($task, $actor, $data): DailyTask {
            $workDate = $data['work_date'];
            $initialStatus = Carbon::parse($workDate, config('app.timezone'))->toDateString() <= now()->toDateString()
                ? 'pending'
                : 'planned';

            $dailyTask = $task->dailyTasks()->create([
                ...$data,
                'status' => $initialStatus,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
                'original_work_date' => $workDate,
                'original_planned_start_time' => $data['planned_start_time'],
                'original_planned_end_time' => $data['planned_end_time'],
            ]);

            $this->activity->record($dailyTask, $actor, 'daily_task_created', null, $initialStatus, $data['remarks'] ?? null);
            $this->activity->record(
                $dailyTask,
                $actor,
                'daily_task_assigned',
                null,
                (string) $dailyTask->assigned_to,
                'Assigned to user #'.$dailyTask->assigned_to,
            );

            return $dailyTask->fresh(['task', 'subtask', 'assignee', 'creator']) ?? $dailyTask;
        });
    }

    public function start(DailyTask $dailyTask, User $actor, ?string $remarks = null): DailyTask
    {
        $this->authorizeStatusChange($dailyTask, $actor);
        $this->assertTransition($dailyTask, 'in_progress');

        return $this->persist($dailyTask, $actor, [
            'status' => 'in_progress',
            'actual_started_at' => $dailyTask->actual_started_at ?? now(),
            'missed_at' => null,
            'remarks' => $remarks ?? $dailyTask->remarks,
        ], 'daily_task_started', $remarks);
    }

    public function hold(DailyTask $dailyTask, User $actor, ?string $remarks = null): DailyTask
    {
        $this->authorizeStatusChange($dailyTask, $actor);
        $this->assertTransition($dailyTask, 'on_hold');

        return $this->persist($dailyTask, $actor, [
            'status' => 'on_hold',
            'hold_reason' => $remarks,
            'remarks' => $remarks ?? $dailyTask->remarks,
        ], 'daily_task_on_hold', $remarks);
    }

    public function resume(DailyTask $dailyTask, User $actor, ?string $remarks = null): DailyTask
    {
        $this->authorizeStatusChange($dailyTask, $actor);
        $this->assertTransition($dailyTask, 'in_progress');

        return $this->persist($dailyTask, $actor, [
            'status' => 'in_progress',
            'hold_reason' => null,
            'remarks' => $remarks ?? $dailyTask->remarks,
        ], 'daily_task_resumed', $remarks);
    }

    public function updateProgress(DailyTask $dailyTask, User $actor, int $progress, ?string $remarks = null): DailyTask
    {
        $this->authorizeStatusChange($dailyTask, $actor);

        if (! in_array($dailyTask->status, ['in_progress', 'on_hold'], true)) {
            throw ValidationException::withMessages([
                'status' => 'Progress can only be updated while the Daily Task is in progress or on hold.',
            ]);
        }

        $oldProgress = (string) $dailyTask->progress_percentage;

        return $this->persist($dailyTask, $actor, [
            'progress_percentage' => $progress,
            'remarks' => $remarks ?? $dailyTask->remarks,
        ], 'daily_task_progress_updated', $remarks, $oldProgress, (string) $progress);
    }

    /**
     * @param  array<string, int|float|string|null>|null  $operationalMetrics
     */
    public function submitUpdate(
        DailyTask $dailyTask,
        User $actor,
        ?string $remarks = null,
        ?string $blockers = null,
        ?array $operationalMetrics = null,
    ): DailyTask {
        $this->authorizeStatusChange($dailyTask, $actor);

        if (! in_array($dailyTask->status, ['in_progress', 'on_hold'], true)) {
            throw ValidationException::withMessages([
                'status' => 'A Daily Update can only be submitted while work is in progress or on hold.',
            ]);
        }

        $updates = [
            'remarks' => $remarks ?? $dailyTask->remarks,
            'blockers' => $blockers ?? $dailyTask->blockers,
        ];

        if ($operationalMetrics !== null) {
            $updates['operational_metrics'] = $operationalMetrics;
        }

        return $this->persist($dailyTask, $actor, $updates, 'daily_task_update_submitted', $remarks);
    }

    public function reassign(DailyTask $dailyTask, User $actor, int $assigneeId, ?string $remarks = null): DailyTask
    {
        Gate::forUser($actor)->authorize('update', $dailyTask->task);

        if (in_array($dailyTask->status, ['completed', 'cancelled'], true)) {
            throw ValidationException::withMessages([
                'assigned_to' => 'Completed or cancelled Daily Tasks cannot be reassigned.',
            ]);
        }

        $oldAssignee = (string) $dailyTask->assigned_to;

        return $this->persist($dailyTask, $actor, [
            'assigned_to' => $assigneeId,
            'remarks' => $remarks ?? $dailyTask->remarks,
        ], 'daily_task_reassigned', $remarks, $oldAssignee, (string) $assigneeId);
    }

    /**
     * @param  array{work_date: string, planned_start_time: string, planned_end_time: string, estimated_minutes?: int|null}  $schedule
     */
    public function reschedule(DailyTask $dailyTask, User $actor, array $schedule, ?string $remarks = null): DailyTask
    {
        Gate::forUser($actor)->authorize('update', $dailyTask->task);

        if (in_array($dailyTask->status, ['completed', 'cancelled'], true)) {
            throw ValidationException::withMessages([
                'work_date' => 'Completed or cancelled Daily Tasks cannot be rescheduled.',
            ]);
        }

        $oldSchedule = $dailyTask->work_date?->toDateString().' '.$dailyTask->formatTime($dailyTask->planned_start_time)
            .'-'.$dailyTask->formatTime($dailyTask->planned_end_time);
        $newStatus = $dailyTask->status === 'missed' ? 'pending' : $dailyTask->status;
        $newSchedule = $schedule['work_date'].' '.$schedule['planned_start_time'].'-'.$schedule['planned_end_time'];

        return $this->persist($dailyTask, $actor, [
            'work_date' => $schedule['work_date'],
            'planned_start_time' => $schedule['planned_start_time'],
            'planned_end_time' => $schedule['planned_end_time'],
            'estimated_minutes' => $schedule['estimated_minutes'] ?? $dailyTask->estimated_minutes,
            'status' => $newStatus,
            'missed_at' => $newStatus === 'missed' ? $dailyTask->missed_at : null,
            'remarks' => $remarks ?? $dailyTask->remarks,
        ], 'daily_task_rescheduled', $remarks, $oldSchedule, $newSchedule);
    }

    public function complete(DailyTask $dailyTask, User $actor, ?string $remarks = null): DailyTask
    {
        $this->authorizeStatusChange($dailyTask, $actor);
        $this->assertTransition($dailyTask, 'completed');

        return $this->persist($dailyTask, $actor, [
            'status' => 'completed',
            'progress_percentage' => 100,
            'actual_ended_at' => now(),
            'completion_remarks' => $remarks,
            'remarks' => $remarks ?? $dailyTask->remarks,
        ], 'daily_task_completed', $remarks);
    }

    public function cancel(DailyTask $dailyTask, User $actor, ?string $remarks = null): DailyTask
    {
        Gate::forUser($actor)->authorize('updateDailyTask', [$dailyTask->task, $dailyTask]);

        $this->assertTransition($dailyTask, 'cancelled');

        return $this->persist($dailyTask, $actor, [
            'status' => 'cancelled',
            'actual_ended_at' => now(),
            'remarks' => $remarks ?? $dailyTask->remarks,
        ], 'daily_task_cancelled', $remarks);
    }

    public function refreshDueStatuses(?Carbon $now = null): int
    {
        $now ??= now();
        $updated = 0;

        DailyTask::query()
            ->whereIn('status', ['planned', 'pending'])
            ->orderBy('id')
            ->each(function (DailyTask $dailyTask) use ($now, &$updated): void {
                if ($dailyTask->shouldBecomeMissed($now)) {
                    DB::transaction(function () use ($dailyTask, $now): void {
                        $oldStatus = $dailyTask->status;
                        $dailyTask->update([
                            'status' => 'missed',
                            'missed_at' => $now,
                        ]);
                        $this->activity->record(
                            $dailyTask,
                            null,
                            'daily_task_missed',
                            $oldStatus,
                            'missed',
                            'Marked missed after the planned end time passed.',
                        );
                    });
                    $updated++;

                    return;
                }

                if ($dailyTask->shouldBecomePending($now)) {
                    $dailyTask->update(['status' => 'pending']);
                    $updated++;
                }
            });

        return $updated;
    }

    /**
     * @param  array<string, mixed>  $updates
     */
    private function persist(
        DailyTask $dailyTask,
        ?User $actor,
        array $updates,
        string $event,
        ?string $remarks = null,
        ?string $oldValue = null,
        ?string $newValue = null,
    ): DailyTask {
        return DB::transaction(function () use ($dailyTask, $actor, $updates, $event, $remarks, $oldValue, $newValue): DailyTask {
            $oldStatus = $dailyTask->status;
            $dailyTask->fill($updates);
            $dailyTask->updated_by = $actor?->id ?? $dailyTask->updated_by;
            $dailyTask->save();

            $this->activity->record(
                $dailyTask,
                $actor,
                $event,
                $oldValue ?? $oldStatus,
                $newValue ?? ($updates['status'] ?? $dailyTask->status),
                $remarks,
            );

            return $dailyTask->fresh(['task', 'subtask', 'assignee', 'creator']) ?? $dailyTask;
        });
    }

    private function authorizeStatusChange(DailyTask $dailyTask, User $actor): void
    {
        Gate::forUser($actor)->authorize('updateDailyTask', [$dailyTask->task, $dailyTask]);
    }

    private function assertTransition(DailyTask $dailyTask, string $newStatus): void
    {
        $allowed = DailyTask::STATUS_TRANSITIONS[$dailyTask->status] ?? [];

        if (! in_array($newStatus, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => 'This Daily Task cannot move from '.DailyTask::STATUS_LABELS[$dailyTask->status]
                    .' to '.DailyTask::STATUS_LABELS[$newStatus].'.',
            ]);
        }
    }
}
