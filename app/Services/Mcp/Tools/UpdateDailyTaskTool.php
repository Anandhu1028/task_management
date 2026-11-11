<?php

namespace App\Services\Mcp\Tools;

use App\Models\Subtask;
use App\Models\SubtaskDailyUpdate;
use App\Models\Task;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class UpdateDailyTaskTool implements McpToolInterface
{
    public function name(): string
    {
        return 'update_daily_task';
    }

    public function description(): string
    {
        return 'Submit or update a Daily Work Report (DWR) entry for an assigned subtask, recording hours spent, progress percentage, and work notes.';
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
                'update_text' => [
                    'type' => 'string',
                    'description' => 'Description of work completed or progress achieved today.',
                ],
                'progress_percentage' => [
                    'type' => 'integer',
                    'description' => 'Current completion percentage (0 - 100).',
                    'minimum' => 0,
                    'maximum' => 100,
                ],
                'hours_spent' => [
                    'type' => 'number',
                    'description' => 'Hours spent on this subtask today (e.g. 1.5).',
                ],
                'is_completed' => [
                    'type' => 'boolean',
                    'description' => 'Whether this subtask is now fully completed.',
                ],
            ],
            'required' => ['subtask_id', 'update_text'],
        ];
    }

    public function execute(User $user, array $arguments): array
    {
        $subtaskId = (int) ($arguments['subtask_id'] ?? 0);
        $updateText = trim((string) ($arguments['update_text'] ?? ''));

        if ($subtaskId <= 0 || $updateText === '') {
            throw new \InvalidArgumentException('Valid subtask_id and update_text are required.');
        }

        $subtask = Subtask::query()
            ->with('task')
            ->where('id', $subtaskId)
            ->first();

        if (! $subtask || ! $subtask->task) {
            throw new \InvalidArgumentException('Subtask not found.');
        }

        // Verify task is visible to this user
        $isTaskVisible = Task::query()->visibleTo($user)->whereKey($subtask->task_id)->exists();
        if (! $isTaskVisible) {
            throw new \InvalidArgumentException('Access denied: You do not have permission to access the parent task.');
        }

        // Only the assigned user or elevated managers can update subtasks
        $isAssignee = (int) $subtask->assigned_to === (int) $user->id;
        $isElevated = $user->hasAssignedRole('Admin')
            || $user->hasAssignedRole('CEO')
            || $user->hasAssignedRole('Manager')
            || ($user->hasAssignedRole('Team Leader') && $user->department_id === $subtask->task->department_id);

        if (! $isAssignee && ! $isElevated) {
            throw new \InvalidArgumentException('Permission denied: You can only submit daily updates for subtasks assigned to you.');
        }

        $progress = isset($arguments['progress_percentage'])
            ? min(100, max(0, (int) $arguments['progress_percentage']))
            : (int) $subtask->progress_percentage;

        $isCompleted = isset($arguments['is_completed'])
            ? (bool) $arguments['is_completed']
            : ($progress >= 100);

        if ($isCompleted) {
            $progress = 100;
        }

        $hoursSpent = isset($arguments['hours_spent'])
            ? max(0.0, (float) $arguments['hours_spent'])
            : 0.0;

        $today = Carbon::today()->toDateString();

        $dailyUpdate = DB::transaction(function () use ($subtask, $user, $today, $updateText, $progress, $hoursSpent, $isCompleted): SubtaskDailyUpdate {
            $subtaskUpdateData = [
                'progress_percentage' => $progress,
                'is_completed' => $isCompleted,
            ];

            if ($isCompleted) {
                $subtaskUpdateData['status'] = 'completed';
                $subtaskUpdateData['completed_at'] = now();
                $subtaskUpdateData['completed_by'] = $user->id;
            } else {
                $subtaskUpdateData['status'] = 'in_progress';
            }

            $subtask->update($subtaskUpdateData);

            return SubtaskDailyUpdate::create([
                'subtask_id' => $subtask->id,
                'employee_id' => $user->id,
                'activity_date' => $today,
                'update_text' => $updateText,
                'progress_percentage' => $progress,
                'hours_spent' => $hoursSpent,
                'is_completed' => $isCompleted,
            ]);
        });

        return [
            'success' => true,
            'message' => 'Daily work report update submitted successfully.',
            'update' => [
                'id' => $dailyUpdate->id,
                'subtask_id' => $subtask->id,
                'subtask_title' => $subtask->title,
                'parent_task_code' => $subtask->task->task_code,
                'progress_percentage' => $progress,
                'is_completed' => $isCompleted,
                'date' => $today,
            ],
        ];
    }
}
