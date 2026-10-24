<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('users.view');
    }

    public function view(User $user, User $model): bool
    {
        return $user->can('users.view');
    }

    public function create(User $user): bool
    {
        return $user->can('users.create');
    }

    public function update(User $user, User $model): bool
    {
        return $user->can('users.update')
            && (! $model->requires_approval || $user->hasRole('Admin'));
    }

    public function changeStatus(User $user, User $model): bool
    {
        return $user->can('users.change_status')
            && (! $model->requires_approval || $user->hasRole('Admin'));
    }

    public function delete(User $user, User $model): bool
    {
        return $user->can('users.delete');
    }

    public function restore(User $user, User $model): bool
    {
        return $user->can('users.update');
    }

    public function forceDelete(User $user, User $model): bool
    {
        return $user->can('users.delete');
    }
}
