<?php

namespace App\Policies;

use App\Models\Subtask;
use App\Models\SubtaskDailyUpdate;
use App\Models\Task;
use App\Models\User;

class SubtaskDailyUpdatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('tasks.view');
    }

    public function view(User $user, SubtaskDailyUpdate $subtaskDailyUpdate): bool
    {
        if ($user->hasAssignedRole('Admin')
            || $user->hasAssignedRole('CEO')
            || $user->hasAssignedRole('Manager')
            || $user->hasAssignedRole('Team Leader')) {
            return $subtaskDailyUpdate->task_id !== null
                && Task::query()->visibleTo($user)->whereKey($subtaskDailyUpdate->task_id)->exists();
        }

        return $user->can('tasks.view')
            && (int) $subtaskDailyUpdate->employee_id === (int) $user->id;
    }

    public function create(User $user, SubtaskDailyUpdate $subtaskDailyUpdate): bool
    {
        $subtask = $subtaskDailyUpdate->subtask;
        if (! $subtask instanceof Subtask || $subtask->task_id !== $subtaskDailyUpdate->task_id) {
            return false;
        }

        return $user->can('tasks.view')
            && (int) $subtask->assigned_to === (int) $user->id
            && Task::query()->visibleTo($user)->whereKey($subtask->task_id)->exists();
    }
}
