<?php

namespace App\Services\Mcp\Tools;

use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

class GetTaskReportTool implements McpToolInterface
{
    public function name(): string
    {
        return 'get_task_report';
    }

    public function description(): string
    {
        return 'Generate an accurate task report (daily, weekly, monthly, or custom period) with total tasks, completed, in-progress, pending, overdue counts, completion percentage, and task breakdown.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'period' => [
                    'type' => 'string',
                    'description' => 'Report time window: daily, weekly, monthly, or custom (default: weekly).',
                    'enum' => ['daily', 'weekly', 'monthly', 'custom'],
                ],
                'from_date' => [
                    'type' => 'string',
                    'description' => 'Custom start date (YYYY-MM-DD). Required if period is custom.',
                ],
                'to_date' => [
                    'type' => 'string',
                    'description' => 'Custom end date (YYYY-MM-DD). Defaults to today if omitted.',
                ],
                'project' => [
                    'type' => 'string',
                    'description' => 'Optional filter by specific project.',
                ],
                'company_id' => [
                    'type' => 'integer',
                    'description' => 'Filter by company ID (Admin/CEO only).',
                ],
                'department_id' => [
                    'type' => 'integer',
                    'description' => 'Filter by department ID (Admin/CEO/Manager only).',
                ],
                'include_tasks' => [
                    'type' => 'boolean',
                    'description' => 'Whether to include the list of task summaries in the report (default: true).',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(User $user, array $arguments): array
    {
        $isAdminOrCeo = $user->hasAssignedRole('Admin') || $user->hasAssignedRole('CEO');
        $isElevated = $isAdminOrCeo || $user->hasAssignedRole('Manager');
        $periodType = $arguments['period'] ?? 'weekly';
        $now = Carbon::now();

        [$start, $end] = match ($periodType) {
            'daily' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'monthly' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            'custom' => [
                ! empty($arguments['from_date']) ? Carbon::parse($arguments['from_date'])->startOfDay() : $now->copy()->subDays(6)->startOfDay(),
                ! empty($arguments['to_date']) ? Carbon::parse($arguments['to_date'])->endOfDay() : $now->copy()->endOfDay(),
            ],
            default => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()],
        };

        $buildBase = function () use ($user, $start, $end, $arguments, $isAdminOrCeo, $isElevated): Builder {
            $q = Task::query()
                ->visibleTo($user)
                ->whereBetween('tasks.created_at', [$start, $end]);

            if (! empty($arguments['project'])) {
                $q->where('tasks.project', 'like', '%'.trim((string) $arguments['project']).'%');
            }

            if (! empty($arguments['company_id']) && $isAdminOrCeo) {
                $q->where('tasks.company_id', (int) $arguments['company_id']);
            }

            if (! empty($arguments['department_id']) && $isElevated) {
                $q->where('tasks.department_id', (int) $arguments['department_id']);
            }

            return $q;
        };

        $baseQuery = $buildBase;

        $totalTasks = (clone $baseQuery())->count();
        $completedTasks = (clone $baseQuery())->where('tasks.status', 'completed')->count();
        $inProgressTasks = (clone $baseQuery())->where('tasks.status', 'in_progress')->count();
        $pendingTasks = (clone $baseQuery())->whereIn('tasks.status', ['notified', 'unassigned', 'assigned'])->count();
        $onHoldTasks = (clone $baseQuery())->where('tasks.status', 'on_hold')->count();
        $cancelledTasks = (clone $baseQuery())->where('tasks.status', 'cancelled')->count();
        $overdueTasks = (clone $baseQuery())->overdue()->count();

        $completionPercentage = $totalTasks > 0
            ? round(($completedTasks / $totalTasks) * 100, 1)
            : 0.0;

        $taskList = [];
        if (($arguments['include_tasks'] ?? true) !== false) {
            $taskList = (clone $baseQuery())
                ->with(['department:id,name', 'assignee:id,name'])
                ->orderByDesc('tasks.created_at')
                ->limit(30)
                ->get()
                ->map(fn (Task $t): array => [
                    'id' => $t->id,
                    'task_code' => $t->task_code,
                    'title' => $t->title,
                    'project' => $t->project,
                    'status' => $t->status,
                    'status_label' => Task::STATUS_LABELS[$t->status] ?? $t->status,
                    'priority' => $t->priority,
                    'progress_percentage' => (int) $t->progress_percentage,
                    'is_overdue' => $t->isOverdue(),
                    'due_date' => ($t->due_date ?? $t->original_deadline)?->toDateString(),
                    'department' => $t->department?->name,
                    'assignee' => $t->assignee?->name,
                ])
                ->values()
                ->all();
        }

        return [
            'report_type' => $periodType,
            'period' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
            ],
            'summary' => [
                'total_tasks' => $totalTasks,
                'completed' => $completedTasks,
                'in_progress' => $inProgressTasks,
                'pending' => $pendingTasks,
                'on_hold' => $onHoldTasks,
                'cancelled' => $cancelledTasks,
                'overdue' => $overdueTasks,
                'completion_percentage' => $completionPercentage,
            ],
            'applied_filters' => array_filter([
                'period' => $periodType,
                'from_date' => $arguments['from_date'] ?? null,
                'to_date' => $arguments['to_date'] ?? null,
                'project' => $arguments['project'] ?? null,
                'company_id' => isset($arguments['company_id']) ? (int) $arguments['company_id'] : null,
                'department_id' => isset($arguments['department_id']) ? (int) $arguments['department_id'] : null,
            ]),
            'tasks' => $taskList,
            'generated_at' => Carbon::now()->toIso8601String(),
        ];
    }
}
