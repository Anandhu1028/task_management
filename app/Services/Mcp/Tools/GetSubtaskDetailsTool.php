<?php

namespace App\Services\Mcp\Tools;

use App\Models\Subtask;
use App\Models\SubtaskDailyUpdate;
use App\Models\Task;
use App\Models\User;

class GetSubtaskDetailsTool implements McpToolInterface
{
    public function name(): string
    {
        return 'get_subtask_details';
    }

    public function description(): string
    {
        return 'Retrieve full details, assignment, progress, and daily work updates history for a specific subtask ID.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'subtask_id' => [
                    'type' => 'integer',
                    'description' => 'The numeric ID of the subtask.',
                ],
            ],
            'required' => ['subtask_id'],
        ];
    }

    public function execute(User $user, array $arguments): array
    {
        $subtaskId = (int) ($arguments['subtask_id'] ?? 0);
        if ($subtaskId <= 0) {
            throw new \InvalidArgumentException('A valid subtask_id is required.');
        }

        $subtask = Subtask::query()
            ->with([
                'task:id,task_code,title,project,company_id,department_id,status',
                'assignee:id,name,designation',
                'creator:id,name',
                'dailyUpdates' => fn ($q) => $q->orderByDesc('activity_date')->take(10),
            ])
            ->where('id', $subtaskId)
            ->first();

        if (! $subtask || ! $subtask->task) {
            return [
                'found' => false,
                'message' => 'Subtask not found.',
            ];
        }

        // Parent task must be visible to the user
        $isTaskVisible = Task::query()->visibleTo($user)->whereKey($subtask->task_id)->exists();
        if (! $isTaskVisible) {
            return [
                'found' => false,
                'message' => 'Access denied: You do not have permission to view this subtask.',
            ];
        }

        return [
            'found' => true,
            'subtask' => [
                'id' => $subtask->id,
                'title' => $subtask->title,
                'description' => $subtask->description,
                'status' => $subtask->is_completed ? 'completed' : $subtask->status,
                'progress_percentage' => $subtask->is_completed ? 100 : (int) $subtask->progress_percentage,
                'is_completed' => (bool) $subtask->is_completed,
                'estimated_hours' => (float) $subtask->estimated_hours,
                'actual_hours' => (float) $subtask->actual_hours,
                'assignee' => $subtask->assignee?->name ?? 'Unassigned',
                'parent_task' => [
                    'id' => $subtask->task->id,
                    'task_code' => $subtask->task->task_code,
                    'title' => $subtask->task->title,
                    'project' => $subtask->task->project,
                ],
                'recent_daily_updates' => $subtask->dailyUpdates->map(fn (SubtaskDailyUpdate $u): array => [
                    'id' => $u->id,
                    'date' => $u->activity_date?->toDateString(),
                    'update_text' => $u->update_text,
                    'progress_percentage' => (int) $u->progress_percentage,
                    'hours_spent' => (float) $u->hours_spent,
                ])->values()->all(),
            ],
        ];
    }
}
