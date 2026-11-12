<?php

namespace App\Services;

use App\Models\Subtask;
use App\Models\Task;
use App\Models\TaskVolume;
use App\Models\TaskVolumeUpdate;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class VolumeTaskService
{
    public function __construct(private readonly WorkingDayCalculator $workingDays) {}

    public function minimumDailyCount(TaskVolume $volume): int
    {
        $days = $this->workingDays->count($volume->start_date, $volume->deadline_date);

        if ($days === 0) {
            throw ValidationException::withMessages(['volume_deadline' => 'The selected period contains no working days.']);
        }

        return (int) ceil($volume->total_volume / $days);
    }

    public function createConfiguration(Task $task, int $totalVolume, string $startDate, string $deadlineDate): TaskVolume
    {
        if ($deadlineDate < $startDate) {
            throw ValidationException::withMessages([
                'volume_deadline' => 'The deadline date must be on or after the start date.',
            ]);
        }

        $workingDays = $this->workingDays->count($startDate, $deadlineDate);
        if ($workingDays === 0) {
            throw ValidationException::withMessages(['volume_deadline' => 'The selected period contains no working days.']);
        }

        return $task->volume()->create([
            'total_volume' => $totalVolume,
            'start_date' => $startDate,
            'deadline_date' => $deadlineDate,
        ]);
    }

    /** @return array{total:int, completed:int, remaining:int, percentage:int, today_actual_count:int, cumulative_actual:int, cumulative_target:int, working_days:int, minimum_daily_count:int, overall_status:string, rows:Collection<int, array<string, mixed>>, mismatch_count:int} */
    public function summary(Task $task): array
    {
        $volume = $task->volume;
        if ($volume === null) {
            throw new \LogicException('Volume configuration is required for a volume task.');
        }

        $dates = $this->workingDays->dates($volume->start_date, $volume->deadline_date);
        $dateStrings = collect($dates)->map->toDateString();
        $eligibleDates = array_fill_keys($dateStrings->all(), true);
        $updates = $volume->updates()
            ->where('task_id', $task->id)
            ->whereNotNull('subtask_id')
            ->whereHas('subtask', fn ($query) => $query->where('task_id', $task->id))
            ->whereBetween('update_date', [$volume->start_date->toDateString(), $volume->deadline_date->toDateString()])
            ->orderBy('update_date')
            ->get()
            ->filter(fn (TaskVolumeUpdate $update): bool => isset($eligibleDates[$update->update_date->toDateString()]));
        $updatesByDate = $updates
            ->groupBy(fn (TaskVolumeUpdate $update): string => $update->update_date->toDateString())
            ->map(fn (Collection $dayUpdates): array => [
                'actual' => $dayUpdates->count(),
                'has_update' => $dayUpdates->isNotEmpty(),
            ]);
        $minimum = $this->minimumDailyCount($volume);
        $today = today(config('app.timezone'))->toDateString();
        $totalActual = $updates->count();
        $cumulativeActual = 0;
        $eligibleDaysElapsed = 0;
        $rows = collect($dates)
            ->map(function (CarbonImmutable $date) use (
                $updatesByDate,
                $minimum,
                $today,
                $volume,
                &$cumulativeActual,
                &$eligibleDaysElapsed,
            ): array {
                $dateString = $date->toDateString();
                $day = $updatesByDate->get($dateString, ['actual' => 0, 'has_update' => false]);
                $actual = $day['actual'];
                if ($dateString <= $today) {
                    $cumulativeActual += $actual;
                }
                $eligibleDaysElapsed++;
                $cumulativeTarget = min($volume->total_volume, $minimum * $eligibleDaysElapsed);
                $volumeStatus = $dateString > $today
                    ? 'Upcoming'
                    : ($cumulativeActual >= $volume->total_volume
                        ? 'Completed'
                        : ($dateString === $volume->deadline_date->toDateString() && $today > $dateString
                            ? 'Overdue'
                        : ($cumulativeActual < $cumulativeTarget
                            ? 'Behind'
                            : ($cumulativeActual === $cumulativeTarget ? 'Caught Up' : 'Ahead'))));
                $dailyUpdateStatus = $day['has_update']
                    ? 'Submitted'
                    : ($dateString > $today
                        ? 'Upcoming'
                        : ($dateString === $today ? 'Pending' : 'Missed Daily Update'));
                $dailyUpdateLabel = $dailyUpdateStatus === 'Pending'
                    ? "Today's Daily Update Pending"
                    : $dailyUpdateStatus;

                return [
                    'date' => $date,
                    'minimum' => $minimum,
                    'daily_target' => $minimum,
                    'actual' => $actual,
                    'cumulative_target' => $cumulativeTarget,
                    'cumulative_actual' => $cumulativeActual,
                    'has_update' => $day['has_update'],
                    'status' => $volumeStatus,
                    'daily_update_status' => $dailyUpdateStatus,
                    'daily_update_label' => $dailyUpdateLabel,
                ];
            });
        $cumulativeActualToday = (int) $updates
            ->filter(fn (TaskVolumeUpdate $update): bool => $update->update_date->toDateString() <= $today)
            ->count();
        $eligibleDaysElapsedToday = count(array_filter(
            $dateStrings->all(),
            fn (string $date): bool => $date <= $today,
        ));
        $cumulativeTargetToday = min($volume->total_volume, $minimum * $eligibleDaysElapsedToday);
        $todayActual = (int) ($updatesByDate->get($today)['actual'] ?? 0);
        $status = $totalActual >= $volume->total_volume
            ? 'Completed'
            : ($today < $volume->start_date->toDateString()
                ? 'Upcoming'
                : ($today > $volume->deadline_date->toDateString()
                    ? 'Overdue'
                    : ($cumulativeActualToday < $cumulativeTargetToday
                        ? 'Behind'
                        : ($cumulativeActualToday === $cumulativeTargetToday ? 'Caught Up' : 'Ahead'))));

        return [
            'total' => $volume->total_volume,
            'completed' => $totalActual,
            'remaining' => max(0, $volume->total_volume - $totalActual),
            'percentage' => (int) min(100, floor(($totalActual / $volume->total_volume) * 100)),
            'today_actual_count' => $todayActual,
            'cumulative_actual' => $cumulativeActualToday,
            'cumulative_target' => $cumulativeTargetToday,
            'working_days' => $rows->count(),
            'minimum_daily_count' => $minimum,
            'rows' => $rows,
            'overall_status' => $status,
            'mismatch_count' => $status === 'Behind' ? 1 : 0,
        ];
    }

    public function syncFromSubtask(
        Task $task,
        Subtask $subtask,
        User $user,
        string $date,
        ?string $remarks,
    ): void {
        /** @var TaskVolume $volume */
        $volume = $task->volume()->lockForUpdate()->firstOrFail();

        $updates = TaskVolumeUpdate::query()
            ->where('task_id', $task->id)
            ->where('subtask_id', $subtask->id)
            ->orderBy('id')
            ->get();

        $this->validateUpdateDate($volume, $date);
        $existingUpdate = $updates->first(
            fn (TaskVolumeUpdate $update): bool => $update->update_date->toDateString() === $date,
        ) ?? $updates->first();
        $attributes = [
            'task_volume_id' => $volume->id,
            'task_id' => $task->id,
            'subtask_id' => $subtask->id,
            'user_id' => $subtask->assigned_to ?? $existingUpdate?->user_id ?? $subtask->created_by ?? $user->id,
            'update_date' => $date,
            'completed_count' => 1,
            'remarks' => $remarks,
        ];

        if ($existingUpdate instanceof TaskVolumeUpdate) {
            $existingUpdate->fill($attributes)->save();
        } else {
            $existingUpdate = TaskVolumeUpdate::query()->create($attributes);
        }

        foreach ($updates as $update) {
            if ($update->id !== $existingUpdate->id) {
                $update->delete();
            }
        }

        $task->load('volume');
        $task->update(['progress_percentage' => $this->summary($task)['percentage']]);
    }

    private function validateUpdateDate(TaskVolume $volume, string $date): void
    {
        $dateValue = CarbonImmutable::parse($date);
        $eligibleDates = collect($this->workingDays->dates($volume->start_date, $volume->deadline_date))
            ->map->toDateString();

        if ($dateValue->toDateString() > today(config('app.timezone'))->toDateString()
            || ! $eligibleDates->contains($dateValue->toDateString())) {
            throw ValidationException::withMessages([
                'work_date' => 'Volume output must be recorded on a working day within the configured volume period and not in the future.',
            ]);
        }
    }
}
