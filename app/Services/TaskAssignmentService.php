<?php

namespace App\Services;

use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\User;

class TaskAssignmentService
{
    /**
     * @param  array<int, int|string>  $assigneeIds
     */
    public function assigneesBelongToDepartment(array $assigneeIds, int $companyId, int $departmentId): bool
    {
        if ($assigneeIds === []) {
            return true;
        }

        return User::query()
            ->whereIn('id', $assigneeIds)
            ->where('company_id', $companyId)
            ->where('department_id', $departmentId)
            ->where('status', 'active')
            ->count() === count($assigneeIds);
    }

    /**
     * @param  array<int, int|string>  $assigneeIds
     */
    public function syncTaskAssignees(Task $task, array $assigneeIds, string $reason, ?int $assignedBy): void
    {
        $assigneeIds = array_values(array_unique(array_map('intval', $assigneeIds)));
        $now = now();

        if ($task->assigned_to && ! TaskAssignment::query()
            ->where('task_id', $task->id)
            ->where('user_id', $task->assigned_to)
            ->exists()) {
            TaskAssignment::create([
                'task_id' => $task->id,
                'user_id' => $task->assigned_to,
                'assigned_by' => $task->created_by,
                'reason' => 'Initial assignment',
                'assigned_at' => $task->created_at ?? $now,
                'is_active' => true,
            ]);
        }

        $activeAssignments = $task->assignments()
            ->where('is_active', true)
            ->get();
        $activeAssigneeIds = [];

        foreach ($activeAssignments as $assignment) {
            if (in_array((int) $assignment->user_id, $assigneeIds, true) && $assignment->unassigned_at === null) {
                $activeAssigneeIds[] = (int) $assignment->user_id;

                continue;
            }

            $assignment->update([
                'unassigned_at' => $now,
                'is_active' => false,
            ]);
        }

        foreach (array_diff($assigneeIds, array_unique($activeAssigneeIds)) as $assigneeId) {
            TaskAssignment::create([
                'task_id' => $task->id,
                'user_id' => $assigneeId,
                'assigned_by' => $assignedBy,
                'reason' => $reason,
                'assigned_at' => $now,
                'is_active' => true,
            ]);
        }
    }

    public function synchronizeAssignmentStatus(Task $task, ?int $changedBy): void
    {
        $task->refresh();
        $hasActiveAssignee = $task->activeAssignees()->exists();
        $newStatus = match (true) {
            $hasActiveAssignee && $task->status === 'unassigned' => 'assigned',
            ! $hasActiveAssignee && $task->status === 'assigned' => 'unassigned',
            default => null,
        };

        if ($newStatus === null) {
            return;
        }

        $oldStatus = $task->status;
        $task->update(['status' => $newStatus]);
        $task->statusHistory()->create([
            'changed_by' => $changedBy,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'event_type' => 'status',
            'remarks' => 'Status synchronized with the active employee assignment.',
        ]);
    }
}
