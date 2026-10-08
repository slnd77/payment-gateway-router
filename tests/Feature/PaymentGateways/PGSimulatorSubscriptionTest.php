<?php

use App\Classes\PaymentGateways\PGSimulator;
use App\DTO\SubscriptionChargeRequestDTO;
use App\DTO\SubscriptionManageDTO;
use App\Enums\PaymentMethod;
use App\Enums\SubscriptionAction;
use App\Enums\SubscriptionStatus;
use App\Enums\TransactionStatus;
use App\Models\SubscriptionTransaction;
use Devhammed\LaravelBrickMoney\Currency;
use Devhammed\LaravelBrickMoney\Money;

require_once __DIR__.'/GatewayTestHelpers.php';

function makePgSimulatorSub(array $attributes = []): PGSimulator
{
    return new PGSimulator(array_merge([
        'supports_refunds' => true,
        'fees_included_in_amount' => false,
        'fees_rate' => 2.5,
    ], $attributes));
}

it('routes handleSubscriptionRequest to local pg simulator subscription checkout', function () {
    ['subscription' => $subscription, 'client' => $client] = createGatewayTestSubscription('PGSimulator', []);
    $gateway = makePgSimulatorSub();

    $dto = makeSubscriptionRequestDTO($subscription, $client);
    $url = $gateway->handleSubscriptionRequest($dto, $subscription);

    expect($url)->toBe(route('pgSimulatorSubscriptionCheckout', ['subscription' => $subscription->id]));
});

it('handles simulated subscription success response', function () {
    ['subscription' => $subscription] = createGatewayTestSubscription('PGSimulator', []);
    $gateway = makePgSimulatorSub();

    $response = $gateway->handleSubscriptionResponse([
        'subscriptionDbId' => (string) $subscription->id,
        'status' => 'success',
        'subscriptionId' => 'SIMSUB'.$subscription->id,
        'paymentMethod' => 'card',
        'authorizationReference' => 'UMRN1234567890',
    ]);

    expect($response->status)->toBe(SubscriptionStatus::ACTIVE)
        ->and($response->subscriptionId)->toBe('SIMSUB'.$subscription->id)
        ->and($response->authorizationReference)->toBe('UMRN1234567890');

    $subscription->refresh();
    expect($subscription->status)->toBe(SubscriptionStatus::ACTIVE)
        ->and($subscription->payment_method)->toBe(PaymentMethod::CARD);
});

it('handles simulated subscription failure response', function () {
    ['subscription' => $subscription] = createGatewayTestSubscription('PGSimulator', []);
    $gateway = makePgSimulatorSub();

    $response = $gateway->handleSubscriptionResponse([
        'subscriptionDbId' => (string) $subscription->id,
        'status' => 'failed',
    ]);

    expect($response->status)->toBe(SubscriptionStatus::FAILED);

    $subscription->refresh();
    expect($subscription->status)->toBe(SubscriptionStatus::FAILED);
});

it('manages subscription actions (pause, resume, cancel)', function () {
    ['subscription' => $subscription] = createGatewayTestSubscription('PGSimulator', [
        'status' => SubscriptionStatus::ACTIVE,
    ]);
    $gateway = makePgSimulatorSub();

    // Pause
    $pauseDto = new SubscriptionManageDTO(action: SubscriptionAction::PAUSE);
    $res = $gateway->manageSubscription($subscription, $pauseDto);
    expect($res->status)->toBe(SubscriptionStatus::PAUSED);
    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::PAUSED);

    // Resume
    $resumeDto = new SubscriptionManageDTO(action: SubscriptionAction::RESUME);
    $res = $gateway->manageSubscription($subscription, $resumeDto);
    expect($res->status)->toBe(SubscriptionStatus::ACTIVE);
    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::ACTIVE);

    // Cancel
    $cancelDto = new SubscriptionManageDTO(action: SubscriptionAction::CANCEL);
    $res = $gateway->manageSubscription($subscription, $cancelDto);
    expect($res->status)->toBe(SubscriptionStatus::CANCELLED);
    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::CANCELLED);
});

it('charges active subscription in simulator', function () {
    ['subscription' => $subscription] = createGatewayTestSubscription('PGSimulator', [
        'status' => SubscriptionStatus::ACTIVE,
    ]);
    $gateway = makePgSimulatorSub();

    $chargeDto = new SubscriptionChargeRequestDTO(
        site_reference_id: $subscription->site_reference_id,
        charge_reference_id: 'charge_123',
        amount: 10,
        currency: 'INR',
        remarks: 'Monthly renewal'
    );

    $charge = SubscriptionTransaction::create([
        'subscription_id' => $subscription->id,
        'client_id' => $subscription->client_id,
        'site_reference_id' => 'charge_123',
        'amount' => Money::of(10, 'INR'),
        'currency' => Currency::of('INR'),
        'status' => TransactionStatus::PENDING,
    ]);

    $chargeResponse = $gateway->chargeSubscription($subscription, $charge, $chargeDto);

    expect($chargeResponse->status)->toBe(TransactionStatus::SUCCESS)
        ->and($chargeResponse->siteReferenceId)->toBe('charge_123')
        ->and($chargeResponse->transactionId)->not->toBeEmpty();

    $txn = SubscriptionTransaction::where('subscription_id', $subscription->id)
        ->where('site_reference_id', 'charge_123')
        ->first();

    expect($txn)->not->toBeNull()
        ->and($txn->status)->toBe(TransactionStatus::SUCCESS);
});

it('lists charges and gets charge status', function () {
    ['subscription' => $subscription] = createGatewayTestSubscription('PGSimulator', [
        'status' => SubscriptionStatus::ACTIVE,
    ]);
    $gateway = makePgSimulatorSub();

    $chargeDto = new SubscriptionChargeRequestDTO(
        site_reference_id: $subscription->site_reference_id,
        charge_reference_id: 'charge_list_test',
        amount: 15,
        currency: 'INR'
    );

    $charge = SubscriptionTransaction::create([
        'subscription_id' => $subscription->id,
        'client_id' => $subscription->client_id,
        'site_reference_id' => 'charge_list_test',
        'amount' => Money::of(15, 'INR'),
        'currency' => Currency::of('INR'),
        'status' => TransactionStatus::PENDING,
    ]);

    $gateway->chargeSubscription($subscription, $charge, $chargeDto);

    $charges = $gateway->listCharges($subscription);
    expect($charges)->toHaveCount(1);

    $statusDto = $gateway->getChargeStatus($charge->fresh());
    expect($statusDto->status)->toBe(TransactionStatus::SUCCESS);
});
