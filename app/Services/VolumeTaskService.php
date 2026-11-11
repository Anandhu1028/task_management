<?php

namespace App\Services;

use App\Models\Subtask;
use App\Models\Task;
use App\Models\TaskVolume;
use App\Models\TaskVolumeUpdate;
use App\Models\User;
use App\Notifications\TaskLifecycleNotification;
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

    /** @return array{total:int, completed:int, remaining:int, percentage:int, today_actual_count:int, working_days:int, minimum_daily_count:int, rows:Collection<int, array<string, mixed>>, mismatch_count:int} */
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
        $dailyCounts = $updates
            ->groupBy(fn (TaskVolumeUpdate $update): string => $update->update_date->toDateString())
            ->map(fn (Collection $dayUpdates): int => $dayUpdates->pluck('subtask_id')->unique()->count());
        $minimum = $this->minimumDailyCount($volume);
        $today = today(config('app.timezone'))->toDateString();
        $rows = collect($dates)
            ->map(function (CarbonImmutable $date) use ($dailyCounts, $minimum, $today): array {
                $dateString = $date->toDateString();
                $actual = (int) ($dailyCounts[$dateString] ?? 0);
                $hasUpdate = $dailyCounts->has($dateString);
                $status = $dateString > $today
                    ? 'Pending'
                    : ($actual >= $minimum ? 'Achieved' : ($hasUpdate ? 'Mismatch' : 'Missed Update'));

                return [
                    'date' => $date,
                    'minimum' => $minimum,
                    'actual' => $actual,
                    'has_update' => $hasUpdate,
                    'status' => $status,
                ];
            });
        $completed = (int) $dailyCounts->sum();

        return [
            'total' => $volume->total_volume,
            'completed' => $completed,
            'remaining' => max(0, $volume->total_volume - $completed),
            'percentage' => (int) min(100, floor(($completed / $volume->total_volume) * 100)),
            'today_actual_count' => (int) ($dailyCounts[$today] ?? 0),
            'working_days' => $rows->count(),
            'minimum_daily_count' => $minimum,
            'rows' => $rows,
            'mismatch_count' => $rows->whereIn('status', ['Mismatch', 'Missed Update'])->count(),
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
        $volume = $task->volume()->firstOrFail();

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

        $this->refreshProgress($task, $subtask, $user, $date);
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

    private function refreshProgress(Task $task, Subtask $subtask, User $user, ?string $date): void
    {
        $task->load('volume');
        $summary = $this->summary($task);
        $task->update(['progress_percentage' => $summary['percentage']]);

        if ($date === null) {
            return;
        }

        $day = $summary['rows']->first(
            fn (array $row): bool => $row['date']->toDateString() === $date,
        );
        if (($day['status'] ?? null) !== 'Mismatch'
            || $task->statusHistory()
                ->where('event_type', 'volume_minimum_missed')
                ->whereDate('activity_date', $date)
                ->exists()) {
            return;
        }

        $task->statusHistory()->create([
            'subtask_id' => $subtask->id,
            'changed_by' => $user->id,
            'old_status' => $task->status,
            'new_status' => $task->status,
            'event_type' => 'volume_minimum_missed',
            'activity_date' => $date,
            'remarks' => 'Volume minimum of '.$summary['minimum_daily_count'].' was not met.',
        ]);
        User::query()->whereIn('id', $task->pocs()->pluck('users.id'))->get()->each(
            fn (User $poc) => $poc->notify(new TaskLifecycleNotification(
                $task,
                $user->name,
                'Volume daily minimum not met',
                $task->task_code.' is below its daily minimum for '.$date.'.',
            )),
        );
    }
}
