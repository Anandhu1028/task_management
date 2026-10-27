<?php

namespace App\Services\Mcp\Tools;

use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;

class GetOverdueTasksTool implements McpToolInterface
{
    public function name(): string
    {
        return 'get_overdue_tasks';
    }

    public function description(): string
    {
        return 'List all incomplete tasks whose deadline has passed, sorted by urgency with calculated days overdue and current assignees.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'project' => [
                    'type' => 'string',
                    'description' => 'Optional filter by project name.',
                ],
                'department_id' => [
                    'type' => 'integer',
                    'description' => 'Optional filter by department ID.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of overdue tasks to return (default: 25, max: 50).',
                    'minimum' => 1,
                    'maximum' => 50,
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(User $user, array $arguments): array
    {
        $query = Task::query()
            ->visibleTo($user)
            ->overdue()
            ->with(['department:id,name', 'assignee:id,name', 'activeAssignees:id,name']);

        if (! empty($arguments['project'])) {
            $query->where('tasks.project', 'like', '%'.trim((string) $arguments['project']).'%');
        }

        if (! empty($arguments['department_id'])) {
            $query->where('tasks.department_id', (int) $arguments['department_id']);
        }

        $limit = min(50, max(1, (int) ($arguments['limit'] ?? 25)));
        $today = Carbon::today();

        $overdueTasks = $query
            ->orderByRaw('COALESCE(tasks.due_date, tasks.original_deadline) ASC')
            ->orderBy('tasks.id')
            ->limit($limit)
            ->get();

        $tasks = $overdueTasks->map(function (Task $task) use ($today): array {
            $deadline = $task->due_date ?? $task->original_deadline;
            $daysOverdue = $deadline ? (int) $deadline->diffInDays($today) : 0;
            $assignees = $task->activeAssignees->isNotEmpty()
                ? $task->activeAssignees->pluck('name')->all()
                : ($task->assignee ? [$task->assignee->name] : []);

            return [
                'id' => $task->id,
                'task_code' => $task->task_code,
                'title' => $task->title,
                'project' => $task->project,
                'status' => $task->status,
                'status_label' => Task::STATUS_LABELS[$task->status] ?? $task->status,
                'priority' => $task->priority,
                'due_date' => $deadline?->toDateString(),
                'days_overdue' => $daysOverdue,
                'progress_percentage' => (int) $task->progress_percentage,
                'department' => $task->department?->name,
                'assignees' => $assignees,
            ];
        })->values()->all();

        return [
            'total_overdue' => count($tasks),
            'tasks' => $tasks,
            'as_of_date' => $today->toDateString(),
        ];
    }
}
