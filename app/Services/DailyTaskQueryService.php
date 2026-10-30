<?php

namespace App\Services;

use App\Models\DailyTask;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class DailyTaskQueryService
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array{paginator: LengthAwarePaginator<int, DailyTask>, summary: array<string, int>}
     */
    public function paginateForUser(User $user, array $filters, ?int $taskId = null): array
    {
        $query = $this->filteredQuery($user, $filters, $taskId);

        $summary = [
            'total' => (clone $query)->count(),
            'pending' => (clone $query)->whereIn('status', ['planned', 'pending'])->count(),
            'in_progress' => (clone $query)->where('status', 'in_progress')->count(),
            'on_hold' => (clone $query)->where('status', 'on_hold')->count(),
            'completed' => (clone $query)->where('status', 'completed')->count(),
            'overdue' => (clone $query)->where('status', 'missed')->count(),
        ];

        $paginator = (clone $query)
            ->with([
                'task:id,task_code,title,company_id,department_id',
                'task.department:id,name',
                'subtask:id,task_id,title',
                'milestone:id,task_id,title',
                'assignee:id,name',
            ])
            ->sortedForPlanning()
            ->paginate(15)
            ->withQueryString();

        return [
            'paginator' => $paginator,
            'summary' => $summary,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<DailyTask>
     */
    public function filteredQuery(User $user, array $filters, ?int $taskId = null): Builder
    {
        $query = DailyTask::query()->visibleTo($user);

        if ($taskId !== null) {
            $query->where('task_id', $taskId);
        }

        $range = $filters['range'] ?? 'today';
        $today = now()->timezone(config('app.timezone'))->toDateString();
        $yesterday = now()->timezone(config('app.timezone'))->subDay()->toDateString();

        if (($filters['date_from'] ?? null) || ($filters['date_to'] ?? null)) {
            if (! empty($filters['date_from'])) {
                $query->whereDate('work_date', '>=', $filters['date_from']);
            }
            if (! empty($filters['date_to'])) {
                $query->whereDate('work_date', '<=', $filters['date_to']);
            }
        } elseif ($range === 'today') {
            $query->whereDate('work_date', $today);
        } elseif ($range === 'yesterday') {
            $query->whereDate('work_date', $yesterday);
        } elseif ($range === 'upcoming') {
            $query->whereDate('work_date', '>', $today);
        }

        if (! empty($filters['assigned_to'])) {
            $query->where('assigned_to', $filters['assigned_to']);
        }

        if (! empty($filters['task_id'])) {
            $query->where('task_id', $filters['task_id']);
        }

        if (! empty($filters['department_id'])) {
            $query->whereHas('task', fn (Builder $taskQuery) => $taskQuery->where('department_id', $filters['department_id']));
        }

        if (! empty($filters['subtask_id'])) {
            $query->where('subtask_id', $filters['subtask_id']);
        }

        if (! empty($filters['milestone_id'])) {
            $query->where('milestone_id', $filters['milestone_id']);
        }

        if (! empty($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['search'])) {
            $search = '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['search']).'%';
            $query->where(function (Builder $searchQuery) use ($search): void {
                $searchQuery->where('title', 'like', $search)
                    ->orWhere('description', 'like', $search);
            });
        }

        return $query;
    }

    /**
     * @return Collection<int, DailyTask>
     */
    public function overlaps(int $assignedTo, string $workDate, string $startTime, string $endTime, ?int $ignoreId = null): Collection
    {
        return DailyTask::query()
            ->overlapping($assignedTo, $workDate, $startTime, $endTime, $ignoreId)
            ->with(['task:id,task_code,title', 'assignee:id,name'])
            ->get();
    }
}
