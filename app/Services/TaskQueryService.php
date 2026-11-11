<?php

namespace App\Services;

use App\Models\RecurringTask;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TaskQueryService
{
    /**
     * @return array<string, mixed>
     */
    public function validatedFilters(Request $request): array
    {
        return $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(Task::STATUSES)],
            'status_group' => ['nullable', Rule::in(['awaiting_assignment'])],
            'condition' => ['nullable', Rule::in(Task::DEADLINE_CONDITIONS)],
            'priority' => ['nullable', Rule::in(Task::PRIORITIES)],
            'task_type' => ['nullable', Rule::in(Task::TYPES)],
            'sort' => ['nullable', Rule::in([
                'deadline_priority',
                'priority_deadline',
                'nearest_deadline',
                'oldest_deadline',
                'recently_created',
                'recently_updated',
            ])],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'created_by' => ['nullable', 'integer', 'exists:users,id'],
            'scope' => ['nullable', Rule::in(['all', 'assigned', 'created'])],
            'per_page' => ['nullable', Rule::in([10, 15, 25, 50, 100])],
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Task>
     */
    public function filteredTasksQuery(array $filters, User $user): Builder
    {
        $query = Task::query()
            ->visibleTo($user)
            ->availableInRecurringWindow();

        if (($filters['scope'] ?? 'all') === 'assigned') {
            $query->assignedTo($user->id);
        }

        if (($filters['scope'] ?? 'all') === 'created') {
            $query->where('created_by', $user->id);
        }

        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('title', 'like', "%{$search}%")
                    ->orWhere('task_code', 'like', "%{$search}%")
                    ->orWhereHas('company', fn (Builder $q) => $q->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('department', fn (Builder $q) => $q->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('assignee', fn (Builder $q) => $q->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('activeAssignees', fn (Builder $q) => $q->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('pocs', fn (Builder $q) => $q->where('name', 'like', "%{$search}%"));
            });
        }

        if (! empty($filters['company_id'])) {
            $query->where('company_id', $filters['company_id']);
        }

        if (! empty($filters['department_id'])) {
            $query->where('department_id', $filters['department_id']);
        }

        if (! empty($filters['assigned_to'])) {
            $query->assignedTo((int) $filters['assigned_to']);
        }

        if (! empty($filters['created_by'])) {
            $query->where('created_by', $filters['created_by']);
        }

        if (! empty($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }

        if (! empty($filters['task_type'])) {
            if ($filters['task_type'] === 'recurring') {
                $query->where('task_type', 'recurring');
            } else {
                $query->where('task_type', $filters['task_type']);
            }
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (($filters['status_group'] ?? null) === 'awaiting_assignment') {
            $query->whereIn('status', ['notified', 'unassigned', 'assigned']);
        }

        if (! empty($filters['condition'])) {
            match ($filters['condition']) {
                'overdue' => $query->overdue(),
                'at_risk' => $query->atRisk(),
                'normal' => $query->deadlineNormal(),
                default => null,
            };
        }

        return $query;
    }

    /**
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function applySort(Builder $query, string $sort): Builder
    {
        $deadline = 'COALESCE(due_date, original_deadline)';
        $priority = "CASE priority WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 WHEN 'low' THEN 3 ELSE 4 END";
        $nullDeadline = "CASE WHEN {$deadline} IS NULL THEN 1 ELSE 0 END";

        if ($sort === 'recently_created') {
            return $query->orderByDesc('created_at')->orderByDesc('id');
        }

        if ($sort === 'recently_updated') {
            return $query->orderByDesc('updated_at')->orderByDesc('id');
        }

        if ($sort === 'nearest_deadline' || $sort === 'oldest_deadline') {
            $today = today()->toDateString();

            return $query
                ->orderByRaw("CASE WHEN {$deadline} IS NULL THEN 2 WHEN {$deadline} < ? THEN 1 ELSE 0 END", [$today])
                ->orderByRaw("{$deadline} ASC")
                ->orderBy('created_at')
                ->orderBy('id');
        }

        if ($sort === 'priority_deadline') {
            $query->orderByRaw($priority);
        }

        $query->orderByRaw($nullDeadline)
            ->orderByRaw("{$deadline} ASC");

        if ($sort === 'deadline_priority') {
            $query->orderByRaw($priority);
        }

        return $query->orderBy('created_at')->orderBy('id');
    }

    /**
     * @return array<string, int>
     */
    public function counts(User $user): array
    {
        $counts = Task::query()
            ->visibleTo($user)
            ->availableInRecurringWindow()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN status IN ('notified', 'unassigned', 'assigned') THEN 1 ELSE 0 END) as pending_assignment_count")
            ->selectRaw("SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress_count")
            ->selectRaw("SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_count")
            ->first();

        return [
            'total' => (int) ($counts->total ?? 0),
            'pending_assignment_count' => (int) ($counts->pending_assignment_count ?? 0),
            'in_progress_count' => (int) ($counts->in_progress_count ?? 0),
            'completed_count' => (int) ($counts->completed_count ?? 0),
            'overdue_count' => Task::query()->visibleTo($user)->availableInRecurringWindow()->overdue()->count(),
            'at_risk_count' => Task::query()->visibleTo($user)->availableInRecurringWindow()->atRisk()->count(),
        ];
    }

    public function nextRecurringVisibilityTransition(User $user): ?CarbonImmutable
    {
        $now = CarbonImmutable::now('UTC');
        $nextTransition = null;
        $schedules = RecurringTask::query()
            ->where('status', 'active')
            ->whereIn('repeat_frequency', RecurringTask::FREQUENCIES)
            ->whereHas('task', fn (Builder $query) => $query->visibleTo($user))
            ->get(['id', 'task_id', 'repeat_frequency', 'start_date', 'end_date', 'start_time', 'end_time', 'timezone']);

        foreach ($schedules as $schedule) {
            if (! $schedule->hasValidTimezone()
                || ! $schedule->start_date
                || ! $schedule->end_date
                || ! $schedule->start_time
                || ! $schedule->end_time) {
                continue;
            }

            $localNow = $now->setTimezone($schedule->timezone);
            $startsAt = CarbonImmutable::parse($localNow->toDateString().' '.$schedule->start_time, $schedule->timezone);
            $endsAt = CarbonImmutable::parse($localNow->toDateString().' '.$schedule->end_time, $schedule->timezone);

            if ($schedule->isScheduledOn($localNow) && $endsAt->greaterThan($startsAt)) {
                $currentOccurrence = DB::table('tasks')
                    ->select('id')
                    ->selectRaw(
                        'CASE WHEN available_from <= ? AND expires_at >= ? AND status NOT IN (?, ?, ?) THEN 1 ELSE 0 END as is_available',
                        [$now, $now, 'completed', 'missed', 'cancelled'],
                    )
                    ->where('recurring_task_id', $schedule->id)
                    ->where('occurrence_date', $localNow->toDateString())
                    ->first();

                if ($currentOccurrence && (int) $currentOccurrence->is_available === 1) {
                    $candidate = $endsAt->addSecond()->utc();
                } elseif ($localNow->lessThan($startsAt)) {
                    $candidate = $startsAt->utc();
                } elseif (! $currentOccurrence && $localNow->lessThanOrEqualTo($endsAt)) {
                    $candidate = $now->addMinute();
                } else {
                    $candidate = null;
                }

                if ($candidate && $candidate->greaterThan($now)) {
                    $nextTransition = $this->earlierTransition($nextTransition, $candidate);
                }
            }

            $nextDate = $this->nextScheduledDate($schedule, $localNow->startOfDay());
            if ($nextDate) {
                $candidate = CarbonImmutable::parse(
                    $nextDate->toDateString().' '.$schedule->start_time,
                    $schedule->timezone,
                )->utc();

                if ($candidate->greaterThan($now)) {
                    $nextTransition = $this->earlierTransition($nextTransition, $candidate);
                }
            }
        }

        return $nextTransition;
    }

    private function nextScheduledDate(RecurringTask $schedule, CarbonImmutable $today): ?CarbonImmutable
    {
        $startDate = CarbonImmutable::parse($schedule->start_date->toDateString(), $schedule->timezone)->startOfDay();
        $endDate = CarbonImmutable::parse($schedule->end_date->toDateString(), $schedule->timezone)->startOfDay();

        if ($startDate->greaterThan($today)) {
            return $startDate;
        }

        if ($schedule->repeat_frequency === 'daily') {
            $nextDate = $today->addDay();

            return $nextDate->lessThanOrEqualTo($endDate) ? $nextDate : null;
        }

        if ($schedule->repeat_frequency === 'weekly') {
            $daysUntilNextOccurrence = ($startDate->dayOfWeek - $today->dayOfWeek + 7) % 7;
            $nextDate = $today->addDays($daysUntilNextOccurrence === 0 ? 7 : $daysUntilNextOccurrence);

            return $nextDate->lessThanOrEqualTo($endDate) ? $nextDate : null;
        }

        $nextDate = $startDate;
        while ($nextDate->lessThanOrEqualTo($today)) {
            $nextDate = $nextDate->addMonthNoOverflow();
        }

        return $nextDate->lessThanOrEqualTo($endDate) ? $nextDate : null;
    }

    private function earlierTransition(?CarbonImmutable $current, CarbonImmutable $candidate): CarbonImmutable
    {
        return $current === null || $candidate->lessThan($current) ? $candidate : $current;
    }
}
