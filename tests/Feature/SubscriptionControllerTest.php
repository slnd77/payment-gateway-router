<?php

use App\Classes\Encryption;
use App\Enums\ConnectionType;
use App\Enums\SubscriptionAction;
use App\Enums\SubscriptionPeriod;
use App\Enums\SubscriptionStatus;
use App\Enums\SubscriptionType;
use App\Enums\TransactionStatus;
use App\Models\Client;
use App\Models\ClientConnection;
use App\Models\PGConnection;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Str;

function createRecurringTestClient(string $pgClass = 'PGSimulator', array $attributes = [], bool $selfRedirect = true): Client
{
    $user = User::factory()->create();

    $pgConnection = PGConnection::create([
        'name' => $pgClass.' Connection',
        'pg_class' => $pgClass,
        'attributes' => $attributes,
        'status' => true,
        'type' => ConnectionType::TEST,
    ]);

    $client = Client::create([
        'uuid' => (string) Str::ulid(),
        'name' => 'Recurring Test Client',
        'client_id' => Str::upper(Str::random(16)),
        'client_secret' => Str::random(40),
        'website' => 'https://example.test',
        'redirect_uri' => 'https://example.test/subscription/callback',
        'redirect_uri_separator' => '?',
        'status' => true,
        'user_id' => $user->id,
    ]);

    ClientConnection::create([
        'client_id' => $client->id,
        'pg_connection_id' => $pgConnection->id,
        'is_recurring' => true,
        'self_redirect' => $selfRedirect,
        'type' => ConnectionType::TEST,
        'status' => true,
    ]);

    return $client;
}

function encryptedInitSubscriptionUrl(Client $client, string $siteReferenceId, array $overrides = []): string
{
    $data = array_merge([
        'clientId' => $client->client_id,
        'currency' => 'INR',
        'amount' => 10,
        'max_amount' => 10,
        'subscriptionType' => 'periodic',
        'period' => 'monthly',
        'interval' => 1,
        'plan_name' => 'Monthly Plan',
        'reference_id' => $siteReferenceId,
        'customer' => [
            'name' => 'Jane Doe',
            'email' => 'jane@example.test',
            'mobile' => '9876543210',
        ],
    ], $overrides);

    return route('initSubscription', [
        'clientId' => $client->client_id,
        'data' => Encryption::encrypt($data, $client->client_secret),
    ]);
}

function subApiTokenHeader(Client $client): array
{
    return ['X-TOKEN' => $client->client_id.':'.$client->client_secret];
}

it('initiates subscription with self_redirect=true and redirects to checkout', function () {
    $client = createRecurringTestClient('PGSimulator', [], selfRedirect: true);
    $siteReferenceId = 'sub-ref-'.Str::random(12);

    $url = encryptedInitSubscriptionUrl($client, $siteReferenceId);
    $response = $this->get($url);

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('pg-simulator/subscription/checkout');

    $subscription = Subscription::where('client_id', $client->id)
        ->where('site_reference_id', $siteReferenceId)
        ->first();

    expect($subscription)->not->toBeNull()
        ->and($subscription->status)->toBe(SubscriptionStatus::INITIALIZED);
});

it('initiates subscription with self_redirect=false and returns JSON url', function () {
    $client = createRecurringTestClient('PGSimulator', [], selfRedirect: false);
    $siteReferenceId = 'sub-ref-'.Str::random(12);

    $url = encryptedInitSubscriptionUrl($client, $siteReferenceId);
    $response = $this->get($url);

    $response->assertOk()
        ->assertJsonStructure([
            'subscription_url',
            'payment_url',
            'status',
            'status_code',
        ]);
});

it('handles subscription callback and redirects to client redirect_uri', function () {
    $client = createRecurringTestClient('PGSimulator', []);
    $siteReferenceId = 'sub-ref-'.Str::random(12);

    $this->get(encryptedInitSubscriptionUrl($client, $siteReferenceId));
    $subscription = Subscription::where('site_reference_id', $siteReferenceId)->firstOrFail();

    $response = $this->post(route('handleSubscriptionResponse', ['pgClass' => 'PGSimulator']), [
        'subscriptionDbId' => (string) $subscription->id,
        'status' => 'success',
        'subscriptionId' => 'SIMSUB'.$subscription->id,
        'paymentMethod' => 'card',
        'authorizationReference' => 'UMRN1234567890',
    ]);

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('subscription/callback');

    $subscription->refresh();
    expect($subscription->status)->toBe(SubscriptionStatus::ACTIVE)
        ->and($subscription->authorization_reference)->toBe('UMRN1234567890');
});

it('fetches subscription details and lists subscriptions via API', function () {
    $client = createRecurringTestClient('PGSimulator', []);
    $siteReferenceId = 'sub-ref-'.Str::random(12);

    $this->get(encryptedInitSubscriptionUrl($client, $siteReferenceId));

    // Show details
    $showResponse = $this->withHeaders(subApiTokenHeader($client))
        ->getJson(route('subscription.details', ['reference_id' => $siteReferenceId]));

    $showResponse->assertOk()
        ->assertJsonPath('siteReferenceId', $siteReferenceId);

    // List subscriptions
    $listResponse = $this->withHeaders(subApiTokenHeader($client))
        ->getJson(route('subscriptions.list'));

    $listResponse->assertOk()
        ->assertJsonStructure([
            'data',
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ]);

    expect($listResponse->json('data'))->toHaveCount(1);
});

it('manages subscription via manage API endpoint', function () {
    $client = createRecurringTestClient('PGSimulator', []);
    $siteReferenceId = 'sub-ref-'.Str::random(12);

    $this->get(encryptedInitSubscriptionUrl($client, $siteReferenceId));
    $subscription = Subscription::where('site_reference_id', $siteReferenceId)->firstOrFail();
    $subscription->status = SubscriptionStatus::ACTIVE;
    $subscription->save();

    // Pause subscription
    $pauseResponse = $this->withHeaders(subApiTokenHeader($client))
        ->postJson(route('subscription.manage', ['reference_id' => $siteReferenceId]), [
            'action' => SubscriptionAction::PAUSE->value,
        ]);

    $pauseResponse->assertOk()
        ->assertJsonPath('status', SubscriptionStatus::PAUSED->value);

    // Resume subscription
    $resumeResponse = $this->withHeaders(subApiTokenHeader($client))
        ->postJson(route('subscription.manage', ['reference_id' => $siteReferenceId]), [
            'action' => SubscriptionAction::RESUME->value,
        ]);

    $resumeResponse->assertOk()
        ->assertJsonPath('status', SubscriptionStatus::ACTIVE->value);
});

it('charges subscription and queries charges via API endpoints', function () {
    $client = createRecurringTestClient('PGSimulator', []);
    $siteReferenceId = 'sub-ref-'.Str::random(12);

    $this->get(encryptedInitSubscriptionUrl($client, $siteReferenceId));
    $subscription = Subscription::where('site_reference_id', $siteReferenceId)->firstOrFail();
    $subscription->status = SubscriptionStatus::ACTIVE;
    $subscription->save();

    $chargeRef = 'chg-'.Str::random(8);

    // Charge
    $chargeResponse = $this->withHeaders(subApiTokenHeader($client))
        ->postJson(route('subscription.charge', ['reference_id' => $siteReferenceId]), [
            'charge_reference_id' => $chargeRef,
            'amount' => 10,
            'remarks' => 'Monthly fee',
        ]);

    $chargeResponse->assertOk()
        ->assertJsonPath('status', TransactionStatus::SUCCESS->value)
        ->assertJsonPath('siteReferenceId', $chargeRef);

    // List charges
    $listChargesResponse = $this->withHeaders(subApiTokenHeader($client))
        ->getJson(route('subscription.charges.list', ['reference_id' => $siteReferenceId]));

    $listChargesResponse->assertOk()
        ->assertJsonStructure(['data', 'meta']);

    expect($listChargesResponse->json('data'))->toHaveCount(1);

    // Charge details
    $chargeDetailsResponse = $this->withHeaders(subApiTokenHeader($client))
        ->getJson(route('subscription.charges.details', [
            'reference_id' => $siteReferenceId,
            'charge_reference_id' => $chargeRef,
        ]));

    $chargeDetailsResponse->assertOk()
        ->assertJsonPath('siteReferenceId', $chargeRef)
        ->assertJsonPath('status', TransactionStatus::SUCCESS->value);
});

it('initiates on_demand subscription with snake_case subscription_type without period and defaults expiry to 10 years', function () {
    $client = createRecurringTestClient('PGSimulator', [], selfRedirect: true);
    $siteReferenceId = 'sub-ondemand-'.Str::random(12);

    $url = encryptedInitSubscriptionUrl($client, $siteReferenceId, [
        'subscriptionType' => null,
        'subscription_type' => 'on_demand',
        'period' => null,
    ]);
    $response = $this->get($url);

    $response->assertRedirect();

    $subscription = Subscription::where('client_id', $client->id)
        ->where('site_reference_id', $siteReferenceId)
        ->first();

    expect($subscription)->not->toBeNull()
        ->and($subscription->subscription_type)->toBe(SubscriptionType::ON_DEMAND)
        ->and($subscription->period)->toBeNull()
        ->and($subscription->end_date_time)->not->toBeNull()
        ->and($subscription->end_date_time->year)->toBe(now()->addYears(10)->year);
});

it('initiates periodic subscription without period and defaults period to monthly with 10 years expiry', function () {
    $client = createRecurringTestClient('PGSimulator', [], selfRedirect: true);
    $siteReferenceId = 'sub-periodic-'.Str::random(12);

    $url = encryptedInitSubscriptionUrl($client, $siteReferenceId, [
        'subscriptionType' => 'periodic',
        'period' => null,
    ]);
    $response = $this->get($url);

    $response->assertRedirect();

    $subscription = Subscription::where('client_id', $client->id)
        ->where('site_reference_id', $siteReferenceId)
        ->first();

    expect($subscription)->not->toBeNull()
        ->and($subscription->subscription_type)->toBe(SubscriptionType::PERIODIC)
        ->and($subscription->period)->toBe(SubscriptionPeriod::MONTHLY)
        ->and($subscription->max_cycles)->toBe(120)
        ->and($subscription->end_date_time->year)->toBe(now()->addYears(10)->year);
});

it('initiates subscription when period is specified as 10 years string', function () {
    $client = createRecurringTestClient('PGSimulator', [], selfRedirect: true);
    $siteReferenceId = 'sub-10yr-'.Str::random(12);

    $url = encryptedInitSubscriptionUrl($client, $siteReferenceId, [
        'subscriptionType' => 'periodic',
        'period' => '10 years',
    ]);
    $response = $this->get($url);

    $response->assertRedirect();

    $subscription = Subscription::where('client_id', $client->id)
        ->where('site_reference_id', $siteReferenceId)
        ->first();

    expect($subscription)->not->toBeNull()
        ->and($subscription->subscription_type)->toBe(SubscriptionType::PERIODIC)
        ->and($subscription->period)->toBe(SubscriptionPeriod::MONTHLY)
        ->and($subscription->end_date_time->year)->toBe(now()->addYears(10)->year);
});
