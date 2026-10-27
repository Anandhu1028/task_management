<?php

namespace App\Services\Mcp\Tools;

use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class SearchTasksTool implements McpToolInterface
{
    public function name(): string
    {
        return 'search_tasks';
    }

    public function description(): string
    {
        return 'Search tasks by keyword (matches title, description, code, project, department, or assignee) within the authorized scope of the user.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Keyword or phrase to search for in task title, description, code, or project.',
                ],
                'status' => [
                    'type' => 'string',
                    'description' => 'Optional status filter: notified, unassigned, assigned, in_progress, on_hold, submitted_for_review, rework, completed, cancelled.',
                    'enum' => Task::STATUSES,
                ],
                'priority' => [
                    'type' => 'string',
                    'description' => 'Optional priority filter: critical, high, medium, low.',
                    'enum' => Task::PRIORITIES,
                ],
                'project' => [
                    'type' => 'string',
                    'description' => 'Optional project name filter.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of results to return (default: 20, max: 50).',
                    'minimum' => 1,
                    'maximum' => 50,
                ],
            ],
            'required' => ['query'],
        ];
    }

    public function execute(User $user, array $arguments): array
    {
        $search = trim((string) ($arguments['query'] ?? ''));
        if ($search === '') {
            return [
                'total_found' => 0,
                'tasks' => [],
                'message' => 'Search query cannot be empty.',
            ];
        }

        $query = Task::query()
            ->visibleTo($user)
            ->with(['company:id,name', 'department:id,name', 'assignee:id,name', 'activeAssignees:id,name'])
            ->where(function (Builder $builder) use ($search): void {
                $builder->where('tasks.title', 'like', "%{$search}%")
                    ->orWhere('tasks.description', 'like', "%{$search}%")
                    ->orWhere('tasks.task_code', 'like', "%{$search}%")
                    ->orWhere('tasks.project', 'like', "%{$search}%")
                    ->orWhereHas('assignee', fn (Builder $q) => $q->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('activeAssignees', fn (Builder $q) => $q->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('department', fn (Builder $q) => $q->where('name', 'like', "%{$search}%"));
            });

        if (! empty($arguments['status'])) {
            $query->where('tasks.status', $arguments['status']);
        }

        if (! empty($arguments['priority'])) {
            $query->where('tasks.priority', $arguments['priority']);
        }

        if (! empty($arguments['project'])) {
            $query->where('tasks.project', 'like', '%'.trim((string) $arguments['project']).'%');
        }

        $limit = min(50, max(1, (int) ($arguments['limit'] ?? 20)));

        $results = $query
            ->orderByDesc('tasks.updated_at')
            ->limit($limit)
            ->get();

        $tasks = $results->map(function (Task $task): array {
            $deadline = $task->due_date ?? $task->original_deadline;
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
                'progress_percentage' => (int) $task->progress_percentage,
                'deadline_condition' => $task->deadline_condition,
                'is_overdue' => $task->isOverdue(),
                'due_date' => $deadline?->toDateString(),
                'department' => $task->department?->name,
                'assignees' => $assignees,
            ];
        })->values()->all();

        return [
            'query' => $search,
            'total_found' => count($tasks),
            'tasks' => $tasks,
        ];
    }
}
