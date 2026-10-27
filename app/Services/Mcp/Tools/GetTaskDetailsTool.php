<?php

namespace App\Services\Mcp\Tools;

use App\Models\Subtask;
use App\Models\Task;
use App\Models\TaskStatusHistory;
use App\Models\User;

class GetTaskDetailsTool implements McpToolInterface
{
    public function name(): string
    {
        return 'get_task_details';
    }

    public function description(): string
    {
        return 'Retrieve complete details for a single task specified by task_id or task_code, including assignees, subtasks, deadline conditions, and permitted activity history.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'task_id' => [
                    'type' => 'integer',
                    'description' => 'The numeric ID of the task.',
                ],
                'task_code' => [
                    'type' => 'string',
                    'description' => 'The formatted task code (e.g. TSK-0001).',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(User $user, array $arguments): array
    {
        $taskId = ! empty($arguments['task_id']) ? (int) $arguments['task_id'] : null;
        $taskCode = ! empty($arguments['task_code']) ? trim((string) $arguments['task_code']) : null;

        if (! $taskId && ! $taskCode) {
            throw new \InvalidArgumentException('Either task_id or task_code must be provided.');
        }

        $query = Task::query()
            ->visibleTo($user)
            ->with([
                'company:id,name',
                'department:id,name',
                'creator:id,name,designation',
                'assignee:id,name,designation',
                'activeAssignees:id,name,designation',
                'pocs:id,name,designation',
                'reviewer:id,name,designation',
                'subtasks.assignee:id,name',
                'statusHistory.changedBy:id,name',
            ]);

        if ($taskId) {
            $query->where('tasks.id', $taskId);
        } else {
            $query->where('tasks.task_code', $taskCode);
        }

        $task = $query->first();

        if (! $task) {
            return [
                'found' => false,
                'message' => 'Task not found or access denied.',
            ];
        }

        $deadline = $task->due_date ?? $task->original_deadline;
        $assignees = $task->activeAssignees->isNotEmpty()
            ? $task->activeAssignees
            : ($task->assignee ? collect([$task->assignee]) : collect([]));

        $subtasks = $task->subtasks->map(fn (Subtask $st): array => [
            'id' => $st->id,
            'title' => $st->title,
            'description' => $st->description,
            'priority' => $st->priority,
            'status' => $st->status,
            'progress_percentage' => (int) $st->progress_percentage,
            'is_completed' => (bool) $st->is_completed,
            'is_overdue' => $st->is_overdue,
            'due_date' => $st->due_date?->toDateString(),
            'assignee' => $st->assignee?->name ?? 'Unassigned',
        ])->values()->all();

        $history = $task->statusHistory
            ->sortByDesc('created_at')
            ->take(10)
            ->map(fn (TaskStatusHistory $h): array => [
                'event_type' => $h->event_type,
                'old_status' => $h->old_status,
                'new_status' => $h->new_status,
                'changed_by' => $h->changedBy?->name ?? 'System',
                'created_at' => $h->created_at?->toIso8601String(),
            ])->values()->all();

        return [
            'found' => true,
            'task' => [
                'id' => $task->id,
                'task_code' => $task->task_code,
                'title' => $task->title,
                'description' => $task->description,
                'project' => $task->project,
                'task_type' => $task->task_type,
                'priority' => $task->priority,
                'status' => $task->status,
                'status_label' => Task::STATUS_LABELS[$task->status] ?? $task->status,
                'progress_percentage' => (int) $task->progress_percentage,
                'deadline_condition' => $task->deadline_condition,
                'is_overdue' => $task->isOverdue(),
                'start_date' => $task->start_date?->toDateString(),
                'due_date' => $deadline?->toDateString(),
                'original_deadline' => $task->original_deadline?->toDateString(),
                'estimated_hours' => $task->estimated_hours,
                'company' => $task->company?->name,
                'department' => $task->department?->name,
                'created_by' => $task->creator?->name,
                'assignees' => $assignees->map(fn (User $u): array => [
                    'id' => $u->id,
                    'name' => $u->name,
                    'designation' => $u->designation,
                ])->values()->all(),
                'pocs' => $task->pocs->map(fn (User $u): array => [
                    'id' => $u->id,
                    'name' => $u->name,
                    'designation' => $u->designation,
                ])->values()->all(),
                'subtasks' => $subtasks,
                'recent_history' => $history,
            ],
        ];
    }
}
