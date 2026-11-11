<?php

namespace App\Services\Mcp\Tools;

use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;

class GetSubtaskReportTool implements McpToolInterface
{
    public function name(): string
    {
        return 'get_subtask_report';
    }

    public function description(): string
    {
        return 'Retrieve subtask breakdowns, completion rates, and pending subtasks for parent tasks.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'task_id' => [
                    'type' => 'integer',
                    'description' => 'Optional specific parent task ID.',
                ],
                'only_pending' => [
                    'type' => 'boolean',
                    'description' => 'Filter to include only incomplete/pending subtasks (default: false).',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum parent tasks to report (default: 15, max: 50).',
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
            ->has('subtasks')
            ->with(['subtasks.assignee:id,name', 'department:id,name']);

        if (! empty($arguments['task_id'])) {
            $query->where('tasks.id', (int) $arguments['task_id']);
        }

        $limit = min(50, max(1, (int) ($arguments['limit'] ?? 15)));
        $onlyPending = (bool) ($arguments['only_pending'] ?? false);

        $tasks = $query
            ->orderByDesc('tasks.updated_at')
            ->limit($limit)
            ->get();

        $report = $tasks->map(function (Task $task) use ($onlyPending): array {
            $subtasks = $task->subtasks;

            $total = $subtasks->count();
            $completed = $subtasks->where('is_completed', true)->count();
            $pending = $total - $completed;

            $completionPercentage = $total > 0 ? round(($completed / $total) * 100, 1) : 0.0;

            $filteredSubtasks = $onlyPending
                ? $subtasks->where('is_completed', false)
                : $subtasks;

            return [
                'task_id' => $task->id,
                'task_code' => $task->task_code,
                'title' => $task->title,
                'project' => $task->project,
                'department' => $task->department?->name,
                'subtask_metrics' => [
                    'total_subtasks' => $total,
                    'completed_subtasks' => $completed,
                    'pending_subtasks' => $pending,
                    'completion_percentage' => $completionPercentage,
                ],
                'subtasks' => $filteredSubtasks->map(fn (Subtask $st): array => [
                    'id' => $st->id,
                    'title' => $st->title,
                    'description' => $st->description,
                    'status' => $st->is_completed ? 'completed' : $st->status,
                    'progress_percentage' => $st->is_completed ? 100 : (int) $st->progress_percentage,
                    'is_completed' => (bool) $st->is_completed,
                    'assignee' => $st->assignee?->name ?? 'Unassigned',
                ])->values()->all(),
            ];
        })->values()->all();

        return [
            'total_parent_tasks' => count($report),
            'parent_tasks' => $report,
        ];
    }
}
