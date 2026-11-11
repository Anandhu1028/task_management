<?php

namespace App\Services\Mcp\Tools;

use App\Models\Task;
use App\Models\User;

class GetTeamTasksTool implements McpToolInterface
{
    public function name(): string
    {
        return 'get_team_tasks';
    }

    public function description(): string
    {
        return 'Retrieve active and pending tasks assigned across the user\'s team or department. For Team Leaders, Managers, and Admins.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'department_id' => [
                    'type' => 'integer',
                    'description' => 'Optional filter for specific department (if permitted).',
                ],
                'status' => [
                    'type' => 'string',
                    'description' => 'Optional status filter: assigned, in_progress, on_hold, submitted_for_review, rework.',
                    'enum' => Task::STATUSES,
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum tasks to return (default: 20, max: 50).',
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
            ->availableInRecurringWindow()
            ->with(['department:id,name', 'assignee:id,name', 'activeAssignees:id,name']);

        if (! empty($arguments['department_id'])) {
            $deptId = (int) $arguments['department_id'];
            if ($user->hasAssignedRole('Team Leader') && $user->department_id !== $deptId) {
                $query->whereRaw('1 = 0');
            } else {
                $query->where('tasks.department_id', $deptId);
            }
        }

        if (! empty($arguments['status'])) {
            $query->where('tasks.status', $arguments['status']);
        }

        $limit = min(50, max(1, (int) ($arguments['limit'] ?? 20)));

        $tasks = $query
            ->orderByRaw('COALESCE(tasks.due_date, tasks.original_deadline) ASC')
            ->orderByDesc('tasks.id')
            ->limit($limit)
            ->get();

        return [
            'total_team_tasks' => $tasks->count(),
            'tasks' => $tasks->map(function (Task $task): array {
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
                    'priority' => $task->priority,
                    'due_date' => $deadline?->toDateString(),
                    'is_overdue' => $task->isOverdue(),
                    'department' => $task->department?->name,
                    'assignees' => $assignees,
                ];
            })->values()->all(),
        ];
    }
}
