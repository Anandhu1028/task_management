<?php

namespace App\Policies;

use App\Models\DailyTask;
use App\Models\Milestone;
use App\Models\Subtask;
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
        if (! $user->can('tasks.view')) {
            return false;
        }

        return Task::query()->visibleTo($user)->whereKey($task->getKey())->exists();
    }

    public function requestDeadlineExtension(User $user, Task $task): bool
    {
        if (! $user->can('tasks.view')
            || ! $user->can('tasks.change_status')
            || ! $user->hasAssignedRole('Employee')
            || in_array($task->status, ['completed', 'cancelled'], true)
            || ! Task::query()->visibleTo($user)->whereKey($task->getKey())->exists()) {
            return false;
        }

        $isActiveAssignee = $task->relationLoaded('activeAssignees')
            ? $task->activeAssignees->contains('id', $user->id)
            : $task->assignments()
                ->where('user_id', $user->id)
                ->where('is_active', true)
                ->whereNull('unassigned_at')
                ->exists();

        $hasPendingRequest = $task->relationLoaded('deadlineExtensionRequests')
            ? $task->deadlineExtensionRequests->contains('status', 'pending')
            : $task->deadlineExtensionRequests()->where('status', 'pending')->exists();

        return $isActiveAssignee && ! $hasPendingRequest;
    }

    public function reviewDeadlineExtensions(User $user, Task $task): bool
    {
        if (! $user->can('tasks.view')) {
            return false;
        }

        if ($user->hasAssignedRole('Admin') || $user->hasAssignedRole('CEO')) {
            return true;
        }

        if ($user->hasAssignedRole('Manager')) {
            return $user->company_id === $task->company_id;
        }

        if ($user->hasAssignedRole('Team Leader')) {
            return $user->company_id === $task->company_id
                && $user->department_id !== null
                && $user->department_id === $task->department_id;
        }

        return $user->company_id === $task->company_id
            && $user->department_id === $task->department_id
            && ($task->relationLoaded('pocs')
                ? $task->pocs->contains('id', $user->id)
                : $task->pocs()->whereKey($user->id)->exists());
    }

    public function create(User $user): bool
    {
        return $user->can('tasks.create');
    }

    public function update(User $user, Task $task): bool
    {
        return $user->can('tasks.update') && $this->canManage($user, $task);
    }

    public function createSubtask(User $user, Task $task): bool
    {
        return $user->can('subtasks.create') && $this->canManageOperationalTask($user, $task);
    }

    public function createDailyTask(User $user, Task $task): bool
    {
        if ($user->hasAssignedRole('CEO') || $user->hasAssignedRole('Team Leader')) {
            return false;
        }

        if (! Task::query()->visibleTo($user)->whereKey($task->getKey())->exists()) {
            return false;
        }

        if ($user->can('daily_tasks.create_for_others')) {
            return $this->canManageOperationalTask($user, $task);
        }

        return $user->can('daily_tasks.create_own')
            && ($this->isActiveAssignee($user, $task) || $this->isTaskPoc($user, $task));
    }

    public function updateDailyTask(User $user, Task $task, DailyTask $dailyTask): bool
    {
        if ($dailyTask->task_id !== $task->id
            || ! Task::query()->visibleTo($user)->whereKey($task->getKey())->exists()) {
            return false;
        }

        if ($user->hasAssignedRole('CEO') || $user->hasAssignedRole('Team Leader')) {
            return false;
        }

        if ($user->hasAssignedRole('Admin')) {
            return $user->can('daily_tasks.manage') && $this->canManageOperationalTask($user, $task);
        }

        return $user->hasAssignedRole('Employee')
            && (int) $dailyTask->assigned_to === (int) $user->id
            && $user->can('daily_tasks.update_own');
    }

    public function viewDailyTask(User $user, Task $task, DailyTask $dailyTask): bool
    {
        return $dailyTask->task_id === $task->id
            && Task::query()->visibleTo($user)->whereKey($task->getKey())->exists()
            && DailyTask::query()->visibleTo($user)->whereKey($dailyTask->getKey())->exists();
    }

    public function createMilestone(User $user, Task $task): bool
    {
        return $user->can('milestones.create')
            && Task::query()->visibleTo($user)->whereKey($task->getKey())->exists()
            && $this->canManageOperationalTask($user, $task);
    }

    public function updateMilestone(User $user, Task $task, Milestone $milestone): bool
    {
        return $milestone->task_id === $task->id
            && $user->can('milestones.manage')
            && Task::query()->visibleTo($user)->whereKey($task->getKey())->exists()
            && $this->canManageOperationalTask($user, $task);
    }

    public function updateSubtask(User $user, Task $task): bool
    {
        return $user->can('subtasks.manage')
            && Task::query()->visibleTo($user)->whereKey($task->getKey())->exists()
            && $this->canManageOperationalTask($user, $task);
    }

    public function deleteSubtask(User $user, Task $task, ?Subtask $subtask = null): bool
    {
        return $subtask !== null && $this->updateSubtask($user, $task);
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
            && $user->hasAssignedRole('Employee')
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
                    && $user->hasAssignedRole('Employee')
                    && $this->isActiveAssignee($user, $task)));
    }

    private function canManage(User $user, Task $task): bool
    {
        if ($user->hasAssignedRole('Admin') || $user->hasAssignedRole('CEO')) {
            return true;
        }

        if ($user->hasAssignedRole('Manager')) {
            return $user->company_id === $task->company_id;
        }

        return $user->company_id === $task->company_id
            && (($user->hasAssignedRole('Team Leader')
                    && $user->department_id !== null
                    && $user->department_id === $task->department_id)
                || ($user->department_id === $task->department_id && $this->isTaskPoc($user, $task)));
    }

    private function canManageOperationalTask(User $user, Task $task): bool
    {
        if ($user->hasAssignedRole('Admin') || $user->hasAssignedRole('CEO')) {
            return true;
        }

        if ($user->hasAssignedRole('Manager')) {
            return $user->company_id === $task->company_id;
        }

        if ($user->hasAssignedRole('Team Leader')) {
            return $user->company_id === $task->company_id
                && $user->department_id !== null
                && $user->department_id === $task->department_id
                && $this->isTaskPoc($user, $task);
        }

        return $user->company_id === $task->company_id
            && $user->department_id === $task->department_id
            && $this->isTaskPoc($user, $task);
    }

    private function isTaskPoc(User $user, Task $task): bool
    {
        return $task->relationLoaded('pocs')
            ? $task->pocs->contains('id', $user->id)
            : $task->pocs()->whereKey($user->id)->exists();
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
