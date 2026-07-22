<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('can_view_all_user');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, User $users): bool
    {
        return $user->can('can_view_user');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('can_create_user');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, User $users): bool
    {
        return $user->can('can_edit_user');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, User $users): bool
    {
        return $user->can('can_delete_user');
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, User $users): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, User $users): bool
    {
        return false;
    }
}
