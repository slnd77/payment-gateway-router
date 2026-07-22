<?php

namespace App\Policies;

use App\Models\SubscriptionTransaction;
use App\Models\User;

class SubscriptionTransactionPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('can_view_all_transactions') || $user->can('can_view_client');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, SubscriptionTransaction $subscriptionTransaction): bool
    {
        if ($user->can('can_view_all_transactions')) {
            return true;
        }

        return $user->can('can_view_client')
            && $subscriptionTransaction->client->users()->whereKey($user->id)->exists();
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return false;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, SubscriptionTransaction $subscriptionTransaction): bool
    {
        return false;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, SubscriptionTransaction $subscriptionTransaction): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, SubscriptionTransaction $subscriptionTransaction): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, SubscriptionTransaction $subscriptionTransaction): bool
    {
        return false;
    }
}
