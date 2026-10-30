<?php

namespace App\Models;

use Database\Factories\DailyTaskFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class DailyTask extends Model
{
    /** @use HasFactory<DailyTaskFactory> */
    use HasFactory;

    public const PRIORITIES = ['critical', 'high', 'medium', 'low'];

    public const STATUSES = [
        'planned',
        'pending',
        'in_progress',
        'on_hold',
        'completed',
        'cancelled',
        'missed',
    ];

    /** @var array<string, string> */
    public const STATUS_LABELS = [
        'planned' => 'Planned',
        'pending' => 'Pending',
        'in_progress' => 'In Progress',
        'on_hold' => 'On Hold',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
        'missed' => 'Missed',
    ];

    /** @var array<string, string> */
    public const PRIORITY_LABELS = [
        'critical' => 'Critical',
        'high' => 'High',
        'medium' => 'Medium',
        'low' => 'Low',
    ];

    /** @var array<string, list<string>> */
    public const STATUS_TRANSITIONS = [
        'planned' => ['pending', 'in_progress', 'cancelled'],
        'pending' => ['in_progress', 'cancelled', 'missed'],
        'in_progress' => ['on_hold', 'completed', 'cancelled'],
        'on_hold' => ['in_progress', 'cancelled'],
        'completed' => [],
        'cancelled' => [],
        'missed' => ['in_progress', 'cancelled'],
    ];

    protected $attributes = [
        'status' => 'planned',
        'priority' => 'medium',
        'progress_percentage' => 0,
    ];

    protected $fillable = [
        'task_id',
        'subtask_id',
        'milestone_id',
        'assigned_to',
        'created_by',
        'updated_by',
        'title',
        'description',
        'work_date',
        'planned_start_time',
        'planned_end_time',
        'original_work_date',
        'original_planned_start_time',
        'original_planned_end_time',
        'priority',
        'status',
        'progress_percentage',
        'estimated_minutes',
        'operational_metrics',
        'actual_started_at',
        'actual_ended_at',
        'missed_at',
        'hold_reason',
        'blockers',
        'remarks',
        'completion_remarks',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'original_work_date' => 'date',
            'progress_percentage' => 'integer',
            'estimated_minutes' => 'integer',
            'operational_metrics' => 'array',
            'actual_started_at' => 'datetime',
            'actual_ended_at' => 'datetime',
            'missed_at' => 'datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function subtask(): BelongsTo
    {
        return $this->belongsTo(Subtask::class);
    }

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(Milestone::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function activity(): HasMany
    {
        return $this->hasMany(TaskStatusHistory::class, 'daily_task_id')->latest('id');
    }

    public function plannedStartAt(): Carbon
    {
        return $this->combineDateAndTime($this->work_date, $this->planned_start_time);
    }

    public function plannedEndAt(): Carbon
    {
        return $this->combineDateAndTime($this->work_date, $this->planned_end_time);
    }

    public function isOpen(): bool
    {
        return ! in_array($this->status, ['completed', 'cancelled', 'missed'], true);
    }

    public function shouldBecomePending(?Carbon $now = null): bool
    {
        $now ??= now();

        return $this->status === 'planned'
            && $this->work_date !== null
            && $this->work_date->toDateString() <= $now->timezone(config('app.timezone'))->toDateString();
    }

    public function shouldBecomeMissed(?Carbon $now = null): bool
    {
        $now ??= now();

        if (! in_array($this->status, ['planned', 'pending'], true)) {
            return false;
        }

        return $this->plannedEndAt()->lt($now);
    }

    /**
     * @return Collection<int, DailyTask>
     */
    public function overlappingSchedules(): Collection
    {
        return static::query()
            ->overlapping(
                (int) $this->assigned_to,
                $this->work_date?->toDateString() ?? '',
                $this->formatTime($this->planned_start_time),
                $this->formatTime($this->planned_end_time),
                $this->id,
            )
            ->with('task:id,task_code,title')
            ->get();
    }

    /**
     * @param  Builder<DailyTask>  $query
     * @return Builder<DailyTask>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query
            ->whereHas('task', fn (Builder $taskQuery) => $taskQuery->visibleTo($user))
            ->when(
                ! $user->hasAssignedRole('Admin')
                    && ! $user->hasAssignedRole('CEO')
                    && ! $user->hasAssignedRole('Manager')
                    && ! $user->hasAssignedRole('Team Leader')
                    && ! $user->can('daily_tasks.view_team'),
                fn (Builder $dailyTaskQuery) => $dailyTaskQuery->where('assigned_to', $user->id),
            );
    }

    /**
     * @param  Builder<DailyTask>  $query
     * @return Builder<DailyTask>
     */
    public function scopeOverlapping(
        Builder $query,
        int $assignedTo,
        string $workDate,
        string $startTime,
        string $endTime,
        ?int $ignoreId = null,
    ): Builder {
        $startTime = strlen($startTime) === 5 ? $startTime.':00' : $startTime;
        $endTime = strlen($endTime) === 5 ? $endTime.':00' : $endTime;

        return $query
            ->where('assigned_to', $assignedTo)
            ->whereDate('work_date', $workDate)
            ->whereNotIn('status', ['cancelled'])
            ->when($ignoreId, fn (Builder $overlapQuery) => $overlapQuery->whereKeyNot($ignoreId))
            ->where('planned_start_time', '<', $endTime)
            ->where('planned_end_time', '>', $startTime);
    }

    /**
     * @param  Builder<DailyTask>  $query
     * @return Builder<DailyTask>
     */
    public function scopeSortedForPlanning(Builder $query): Builder
    {
        return $query
            ->orderByRaw("CASE priority WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 ELSE 4 END")
            ->orderBy('planned_start_time')
            ->orderBy('id');
    }

    public function formatTime(mixed $value): string
    {
        if ($value instanceof Carbon) {
            return $value->format('H:i:s');
        }

        $raw = (string) $value;

        if (preg_match('/^\d{2}:\d{2}$/', $raw) === 1) {
            return $raw.':00';
        }

        return $raw;
    }

    private function combineDateAndTime(mixed $date, mixed $time): Carbon
    {
        $dateString = $date instanceof Carbon ? $date->toDateString() : (string) $date;
        $timeString = $this->formatTime($time);

        return Carbon::parse($dateString.' '.$timeString, config('app.timezone'));
    }
}
