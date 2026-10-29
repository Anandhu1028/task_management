<?php

namespace App\Policies;

use App\Models\RecurringTaskTemplate;
use App\Models\User;

class RecurringTaskTemplatePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('tasks.view');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, RecurringTaskTemplate $recurringTaskTemplate): bool
    {
        return $user->can('tasks.view')
            && RecurringTaskTemplate::query()->visibleTo($user)->whereKey($recurringTaskTemplate)->exists();
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('tasks.create');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, RecurringTaskTemplate $recurringTaskTemplate): bool
    {
        return $user->can('tasks.update') && ($user->hasAssignedRole('Admin')
            || $user->hasAssignedRole('CEO')
            || ($user->hasAssignedRole('Team Leader')
                && $user->company_id === $recurringTaskTemplate->company_id
                && $user->department_id === $recurringTaskTemplate->department_id));
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, RecurringTaskTemplate $recurringTaskTemplate): bool
    {
        return $this->update($user, $recurringTaskTemplate);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, RecurringTaskTemplate $recurringTaskTemplate): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, RecurringTaskTemplate $recurringTaskTemplate): bool
    {
        return false;
    }
}
