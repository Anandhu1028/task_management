<?php

namespace App\Services\Mcp\Tools;

use App\Models\Task;
use App\Models\TaskStatusHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class UpdateTaskStatusTool implements McpToolInterface
{
    public function name(): string
    {
        return 'update_task_status';
    }

    public function description(): string
    {
        return 'Update the workflow status of an existing task (e.g. in_progress, submitted_for_review, completed, on_hold). Enforces strict role and company authorization.';
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
                'status' => [
                    'type' => 'string',
                    'description' => 'Target status: in_progress, on_hold, submitted_for_review, rework, completed, cancelled.',
                    'enum' => Task::STATUSES,
                ],
                'progress_percentage' => [
                    'type' => 'integer',
                    'description' => 'Optional updated completion percentage (0 - 100).',
                    'minimum' => 0,
                    'maximum' => 100,
                ],
                'reason' => [
                    'type' => 'string',
                    'description' => 'Optional note explaining the status change.',
                ],
            ],
            'required' => ['status'],
        ];
    }

    public function execute(User $user, array $arguments): array
    {
        $taskId = ! empty($arguments['task_id']) ? (int) $arguments['task_id'] : null;
        $taskCode = ! empty($arguments['task_code']) ? trim((string) $arguments['task_code']) : null;
        $newStatus = trim((string) ($arguments['status'] ?? ''));

        if (! in_array($newStatus, Task::STATUSES, true)) {
            throw new \InvalidArgumentException("Invalid status: {$newStatus}.");
        }

        if (! $taskId && ! $taskCode) {
            throw new \InvalidArgumentException('Either task_id or task_code must be provided.');
        }

        $query = Task::query()->visibleTo($user);
        if ($taskId) {
            $query->where('tasks.id', $taskId);
        } else {
            $query->where('tasks.task_code', $taskCode);
        }

        $task = $query->first();
        if (! $task) {
            throw new \InvalidArgumentException('Task not found or access denied within your authorized company.');
        }

        // Check Laravel policy authorization
        if (Gate::forUser($user)->denies('changeStatus', $task)) {
            throw new \InvalidArgumentException('Permission denied: You are not authorized to change the status of this task.');
        }

        $oldStatus = $task->status;
        $progress = isset($arguments['progress_percentage'])
            ? (int) $arguments['progress_percentage']
            : ($newStatus === 'completed' ? 100 : $task->progress_percentage);

        DB::transaction(function () use ($task, $oldStatus, $newStatus, $progress, $user, $arguments): void {
            $updateData = [
                'status' => $newStatus,
                'progress_percentage' => min(100, max(0, $progress)),
            ];

            if ($newStatus === 'completed') {
                $updateData['completed_at'] = now();
            }

            $task->update($updateData);

            TaskStatusHistory::create([
                'task_id' => $task->id,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'event_type' => 'status_change',
                'changed_by' => $user->id,
                'reason' => $arguments['reason'] ?? 'Status updated via MCP',
            ]);
        });

        return [
            'success' => true,
            'message' => "Task {$task->task_code} status updated from '{$oldStatus}' to '{$newStatus}'.",
            'task' => [
                'id' => $task->id,
                'task_code' => $task->task_code,
                'title' => $task->title,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'progress_percentage' => $progress,
            ],
        ];
    }
}
