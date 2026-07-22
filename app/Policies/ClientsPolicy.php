<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\User;

class ClientsPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('can_view_client') || $user->can('can_view_all_clients');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Client $client): bool
    {
        if ($user->can('can_view_all_clients')) {
            return true;
        }

        return $user->can('can_view_client')
            && $client->users()->whereKey($user->id)->exists();
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('can_create_client');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Client $clients): bool
    {
        return $user->can('can_edit_client');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Client $clients): bool
    {
        return $user->can('can_edit_client');
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Client $client): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Client $client): bool
    {
        return false;
    }
}
