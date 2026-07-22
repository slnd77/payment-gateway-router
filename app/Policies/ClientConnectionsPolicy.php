<?php

namespace App\Policies;

use App\Models\ClientConnection;
use App\Models\User;

class ClientConnectionsPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('can_view_client_pg_connection');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, ClientConnection $clientConnection): bool
    {
        return $user->can('can_view_client_pg_connection');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('can_create_client_pg_connection');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, ClientConnection $clientConnection): bool
    {
        return $user->can('can_edit_client_pg_connection');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, ClientConnection $clientConnection): bool
    {
        return $user->can('can_delete_client_pg_connection');
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, ClientConnection $clientConnection): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, ClientConnection $clientConnection): bool
    {
        return false;
    }
}
