<?php

namespace App\Policies;

use App\Models\PGConnection;
use App\Models\User;

class PGConnectionPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('can_view_pg_connection');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, PGConnection $pGConnection): bool
    {
        return $user->can('can_view_pg_connection');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('can_create_pg_connection');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, PGConnection $pGConnection): bool
    {
        return $user->can('can_edit_pg_connection');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, PGConnection $pGConnection): bool
    {
        return $user->can('can_delete_pg_connection');
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, PGConnection $pGConnection): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, PGConnection $pGConnection): bool
    {
        return false;
    }
}
