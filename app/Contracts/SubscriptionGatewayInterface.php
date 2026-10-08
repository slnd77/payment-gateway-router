<?php

namespace App\Contracts;

use App\DTO\SubscriptionChargeRequestDTO;
use App\DTO\SubscriptionChargeResponseDTO;
use App\DTO\SubscriptionManageDTO;
use App\DTO\SubscriptionRequestDTO;
use App\DTO\SubscriptionResponseDTO;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;

interface SubscriptionGatewayInterface
{
    /**
     * Create mandate on gateway and return the checkout/auth URL.
     */
    public function handleSubscriptionRequest(SubscriptionRequestDTO $request, Subscription $subscription): string;

    /**
     * Handle return/callback from gateway authorization flow.
     *
     * @param  array<string, mixed>  $response
     */
    public function handleSubscriptionResponse(array $response): SubscriptionResponseDTO;

    /**
     * Fetch live status and mandate details from gateway.
     */
    public function getSubscriptionStatus(Subscription $subscription): SubscriptionResponseDTO;

    /**
     * Manage subscription lifecycle (CANCEL, PAUSE, RESUME).
     */
    public function manageSubscription(Subscription $subscription, SubscriptionManageDTO $action): SubscriptionResponseDTO;

    /**
     * Raise a recurring charge against an active subscription mandate.
     */
    public function chargeSubscription(
        Subscription $subscription,
        SubscriptionTransaction $charge,
        SubscriptionChargeRequestDTO $request
    ): SubscriptionChargeResponseDTO;

    /**
     * Check status of an individual charge transaction.
     */
    public function getChargeStatus(SubscriptionTransaction $charge): SubscriptionChargeResponseDTO;

    /**
     * List all charges/payments known to gateway for this subscription.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listCharges(Subscription $subscription): array;
}
