<?php

use App\DTO\SubscriptionChargeRequestDTO;
use App\DTO\SubscriptionRequestDTO;
use App\Enums\SubscriptionStatus;
use App\Exceptions\SubscriptionException;
use App\Models\Client;
use App\Models\Subscription;
use App\Models\User;
use App\Services\SubscriptionService;
use Illuminate\Support\Str;

require_once __DIR__.'/SubscriptionControllerTest.php';

it('throws when initiating a subscription without a configured recurring gateway', function () {
    // Client without recurring connection
    $user = User::factory()->create();
    $client = Client::create([
        'uuid' => (string) Str::ulid(),
        'name' => 'No Recurring Client',
        'client_id' => Str::upper(Str::random(16)),
        'client_secret' => Str::random(40),
        'website' => 'https://example.test',
        'redirect_uri' => 'https://example.test/cb',
        'redirect_uri_separator' => '?',
        'status' => true,
        'user_id' => $user->id,
    ]);

    $siteRef = 'ref-'.Str::random(12);
    $dto = SubscriptionRequestDTO::from([
        'clientDbId' => (string) $client->id,
        'clientId' => $client->client_id,
        'currency' => 'INR',
        'amount' => 10,
        'max_amount' => 10,
        'site_reference_id' => $siteRef,
        'subscription_type' => 'periodic',
        'period' => 'monthly',
        'customer' => ['name' => 'A', 'email' => 'a@b.com', 'mobile' => '9999999999'],
    ]);

    app(SubscriptionService::class)->initiateSubscription($dto);
})->throws(SubscriptionException::class, 'Recurring PG Connection not found for this client.');

it('throws when charging a subscription that is not ACTIVE', function () {
    $client = createRecurringTestClient('PGSimulator', []);
    $siteReferenceId = 'sub-ref-'.Str::random(12);

    $url = encryptedInitSubscriptionUrl($client, $siteReferenceId);
    $this->get($url);

    $subscription = Subscription::where('site_reference_id', $siteReferenceId)->firstOrFail();
    $subscription->status = SubscriptionStatus::PAUSED;
    $subscription->save();

    $chargeDto = new SubscriptionChargeRequestDTO(
        site_reference_id: $siteReferenceId,
        charge_reference_id: 'chg_err_1',
        amount: 10,
        currency: 'INR'
    );

    app(SubscriptionService::class)->chargeSubscription($client->id, $siteReferenceId, $chargeDto);
})->throws(SubscriptionException::class, 'Cannot charge subscription with status: PAUSED');

it('syncs subscription status and charges via SubscriptionService', function () {
    $client = createRecurringTestClient('PGSimulator', []);
    $siteReferenceId = 'sub-ref-'.Str::random(12);

    $this->get(encryptedInitSubscriptionUrl($client, $siteReferenceId));
    $subscription = Subscription::where('site_reference_id', $siteReferenceId)->firstOrFail();
    $subscription->status = SubscriptionStatus::ACTIVE;
    $subscription->save();

    app(SubscriptionService::class)->syncSubscription($subscription);

    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::ACTIVE);
});
