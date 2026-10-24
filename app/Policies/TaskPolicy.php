<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;

class TaskPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('tasks.view');
    }

    public function view(User $user, Task $task): bool
    {
        return $user->can('tasks.view')
            && Task::query()->visibleTo($user)->whereKey($task->getKey())->exists();
    }

    public function create(User $user): bool
    {
        return $user->can('tasks.create');
    }

    public function update(User $user, Task $task): bool
    {
        return $user->can('tasks.update') && $this->canManage($user, $task);
    }

    public function delete(User $user, Task $task): bool
    {
        return $user->can('tasks.delete') && $this->canManage($user, $task);
    }

    public function changeStatus(User $user, Task $task): bool
    {
        if (! Task::query()->visibleTo($user)->whereKey($task->getKey())->exists()) {
            return false;
        }

        if ($task->status === 'submitted_for_review') {
            return $this->review($user, $task);
        }

        return $user->can('tasks.change_status')
            && ($this->canManage($user, $task) || $this->isActiveAssignee($user, $task));
    }

    public function accept(User $user, Task $task): bool
    {
        return $user->can('tasks.change_status')
            && $task->status === 'notified'
            && $user->company_id === $task->company_id
            && $user->department_id === $task->department_id
            && $task->pocs()->whereKey($user->id)->exists();
    }

    public function startWork(User $user, Task $task): bool
    {
        return $user->can('tasks.change_status')
            && $task->status === 'assigned'
            && Task::query()->visibleTo($user)->whereKey($task->getKey())->exists()
            && ($user->hasRole('Employee') || $user->role_id === 8)
            && $this->isActiveAssignee($user, $task);
    }

    public function addWorkUpdate(User $user, Task $task): bool
    {
        return $user->can('tasks.change_status')
            && $task->status === 'in_progress'
            && Task::query()->visibleTo($user)->whereKey($task->getKey())->exists()
            && ($this->canManage($user, $task) || $this->isActiveAssignee($user, $task));
    }

    public function resume(User $user, Task $task): bool
    {
        $canResumeReview = $task->statusHistory()
            ->where('event_type', 'hold')
            ->where('new_status', 'on_hold')
            ->latest('id')
            ->value('old_status') === 'submitted_for_review'
            && $user->can('tasks.review')
            && $task->reviewer_id === $user->id
            && $user->company_id === $task->company_id
            && ! $this->isActiveAssignee($user, $task);

        return ($user->can('tasks.change_status') || $canResumeReview)
            && $task->status === 'on_hold'
            && Task::query()->visibleTo($user)->whereKey($task->getKey())->exists()
            && ($this->canManage($user, $task) || $this->isActiveAssignee($user, $task) || $canResumeReview);
    }

    public function submitForReview(User $user, Task $task): bool
    {
        return $user->can('tasks.submit_for_review')
            && in_array($task->status, ['in_progress', 'on_hold', 'rework'], true)
            && Task::query()->visibleTo($user)->whereKey($task->getKey())->exists()
            && ($this->canManage($user, $task) || $this->isActiveAssignee($user, $task));
    }

    public function flagRisk(User $user, Task $task): bool
    {
        return $user->can('tasks.flag_risk')
            && Task::query()->visibleTo($user)->whereKey($task->getKey())->exists()
            && ($this->canManage($user, $task) || $this->isActiveAssignee($user, $task));
    }

    public function review(User $user, Task $task): bool
    {
        if (! $user->can('tasks.review')
            || $task->status !== 'submitted_for_review'
            || ! Task::query()->visibleTo($user)->whereKey($task->getKey())->exists()
            || $this->isActiveAssignee($user, $task)) {
            return false;
        }

        return $this->canManage($user, $task)
            || ($task->reviewer_id === $user->id && $user->company_id === $task->company_id);
    }

    public function cancel(User $user, Task $task): bool
    {
        return ! in_array($task->status, ['completed', 'cancelled'], true)
            && Task::query()->visibleTo($user)->whereKey($task->getKey())->exists()
            && (($user->can('tasks.cancel') && $this->canManage($user, $task))
                || ($task->status === 'in_progress'
                    && ($user->hasRole('Employee') || $user->role_id === 8)
                    && $this->isActiveAssignee($user, $task)));
    }

    private function canManage(User $user, Task $task): bool
    {
        if ($user->hasRole('Admin') || $user->role_id === 5
            || $user->hasRole('CEO') || $user->role_id === 6) {
            return true;
        }

        return ($user->hasRole('Team Leader') || $user->role_id === 7)
            && $user->company_id === $task->company_id
            && $user->department_id !== null
            && $user->department_id === $task->department_id;
    }

    private function isActiveAssignee(User $user, Task $task): bool
    {
        return $task->assignments()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->whereNull('unassigned_at')
            ->exists();
    }
}
