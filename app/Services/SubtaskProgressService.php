<?php

namespace App\Services;

use App\Models\Subtask;
use App\Models\Task;
use Illuminate\Database\Eloquent\Collection;

class SubtaskProgressService
{
    /**
     * @return array{
     *     work_completion: int,
     *     weighting: string,
     *     schedule_percentage: ?int,
     *     schedule_health: string,
     *     elapsed_days: ?int,
     *     remaining_days: ?int
     * }
     */
    public function summary(Task $task): array
    {
        $subtasks = $this->subtasksFor($task);
        $workCompletion = $subtasks->isEmpty()
            ? (int) $task->getRawOriginal('progress_percentage')
            : $this->calculateWorkCompletion($subtasks);
        $hasCompleteEffort = $subtasks->isNotEmpty()
            && $subtasks->every(fn (Subtask $subtask): bool => (float) $subtask->estimated_hours > 0);

        return [
            'work_completion' => min(100, max(0, $workCompletion)),
            'weighting' => $subtasks->isEmpty()
                ? 'task_progress'
                : ($hasCompleteEffort ? 'estimated_effort' : 'equal'),
            ...$this->scheduleSummary($task, $workCompletion),
        ];
    }

    public function sync(Task $task): int
    {
        $subtasks = $task->subtasks()->get();
        if ($subtasks->isEmpty()) {
            return (int) $task->getRawOriginal('progress_percentage');
        }

        $progress = $this->calculateWorkCompletion($subtasks);
        if ((int) $task->getRawOriginal('progress_percentage') !== $progress) {
            $task->forceFill(['progress_percentage' => $progress])->saveQuietly();
        }

        return $progress;
    }

    /**
     * @param  Collection<int, Subtask>  $subtasks
     */
    private function calculateWorkCompletion(Collection $subtasks): int
    {
        $hasCompleteEffort = $subtasks->every(
            fn (Subtask $subtask): bool => (float) $subtask->estimated_hours > 0,
        );

        if ($hasCompleteEffort) {
            $totalEffort = (float) $subtasks->sum(fn (Subtask $subtask): float => (float) $subtask->estimated_hours);
            $completedEffort = (float) $subtasks->sum(
                fn (Subtask $subtask): float => (float) $subtask->estimated_hours
                    * $this->subtaskCompletion($subtask) / 100,
            );

            return $totalEffort > 0 ? (int) round($completedEffort / $totalEffort * 100) : 0;
        }

        return (int) round($subtasks->avg(fn (Subtask $subtask): int => $this->subtaskCompletion($subtask)));
    }

    /**
     * @return array{
     *     schedule_percentage: ?int,
     *     schedule_health: string,
     *     elapsed_days: ?int,
     *     remaining_days: ?int
     * }
     */
    private function scheduleSummary(Task $task, int $workCompletion): array
    {
        $start = $task->start_date?->copy()->startOfDay();
        $deadline = ($task->due_date ?? $task->original_deadline)?->copy()->startOfDay();
        $asOf = ($task->completed_at ?? now())->copy()->startOfDay();
        $remainingDays = $deadline === null
            ? null
            : (int) $asOf->diffInDays($deadline, false);
        $elapsedDays = $start === null
            ? null
            : max(0, (int) $start->diffInDays($asOf, false));

        if ($task->status === 'cancelled') {
            $health = 'not_applicable';
            $schedulePercentage = null;
        } elseif ($start !== null && $deadline !== null && $deadline->lt($start)) {
            $health = 'date_conflict';
            $schedulePercentage = null;
        } elseif ($deadline !== null && $remainingDays < 0) {
            $health = 'overdue';
            $schedulePercentage = $start !== null
                ? $this->schedulePercentage($start, $deadline, $elapsedDays ?? 0)
                : null;
        } elseif ($start === null || $deadline === null) {
            $health = 'not_available';
            $schedulePercentage = null;
        } else {
            $schedulePercentage = $this->schedulePercentage($start, $deadline, $elapsedDays ?? 0);
            $difference = $workCompletion - $schedulePercentage;
            $health = match (true) {
                $difference >= 5 => 'ahead_of_schedule',
                $difference <= -5 => 'behind_schedule',
                default => 'on_track',
            };
        }

        return [
            'schedule_percentage' => $schedulePercentage,
            'schedule_health' => $health,
            'elapsed_days' => $elapsedDays,
            'remaining_days' => $remainingDays,
        ];
    }

    private function schedulePercentage(\Illuminate\Support\Carbon $start, \Illuminate\Support\Carbon $deadline, int $elapsedDays): int
    {
        $plannedDays = max(1, (int) $start->diffInDays($deadline));

        return min(100, max(0, (int) round($elapsedDays / $plannedDays * 100)));
    }

    /**
     * @return Collection<int, Subtask>
     */
    private function subtasksFor(Task $task): Collection
    {
        return $task->relationLoaded('subtasks')
            ? $task->subtasks
            : $task->subtasks()->get();
    }

    private function subtaskCompletion(Subtask $subtask): int
    {
        if ($subtask->is_completed || $subtask->status === 'completed') {
            return 100;
        }

        return min(99, max(0, (int) $subtask->progress_percentage));
    }
}
