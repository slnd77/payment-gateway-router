<?php

use App\Classes\PaymentGateways\Razorpay;
use App\DTO\SubscriptionChargeRequestDTO;
use App\DTO\SubscriptionManageDTO;
use App\Enums\SubscriptionAction;
use App\Enums\SubscriptionStatus;
use App\Enums\TransactionStatus;
use App\Models\SubscriptionTransaction;
use Devhammed\LaravelBrickMoney\Currency;
use Devhammed\LaravelBrickMoney\Money;
use WpOrg\Requests\Requests;
use WpOrg\Requests\Transport;

require_once __DIR__.'/GatewayTestHelpers.php';

class RazorpayMockTransport implements Transport
{
    /** @var array<int, array{status?: int, body: array<string, mixed>|string}> */
    public static array $queue = [];

    public function request($url, $headers = [], $data = [], $options = [])
    {
        $resp = array_shift(self::$queue) ?? ['status' => 200, 'body' => []];
        $status = $resp['status'] ?? 200;
        $body = is_array($resp['body']) ? json_encode($resp['body']) : (string) $resp['body'];

        return "HTTP/1.1 {$status} OK\r\nContent-Type: application/json\r\n\r\n{$body}";
    }

    public function request_multiple($requests, $options)
    {
        return [];
    }

    public static function test($capabilities = [])
    {
        return true;
    }
}

/**
 * @param  array<int, array{status?: int, body: array<string, mixed>|string}>  $responses
 */
function mockRazorpayHttp(array $responses): void
{
    RazorpayMockTransport::$queue = $responses;

    $ref = new ReflectionClass(Requests::class);
    $prop = $ref->getProperty('transports');
    $prop->setAccessible(true);
    $prop->setValue(null, [RazorpayMockTransport::class => RazorpayMockTransport::class]);
    Requests::$transport = [];
}

function resetRazorpayHttp(): void
{
    RazorpayMockTransport::$queue = [];

    $ref = new ReflectionClass(Requests::class);
    $prop = $ref->getProperty('transports');
    $prop->setAccessible(true);
    $prop->setValue(null, Requests::DEFAULT_TRANSPORTS);
    Requests::$transport = [];
}

afterEach(function () {
    resetRazorpayHttp();
});

/**
 * @param  array<string, mixed>  $attributes
 */
function makeRazorpaySub(array $attributes = []): Razorpay
{
    return new Razorpay(array_merge([
        'key_id' => 'rzp_test_key',
        'key_secret' => 'rzp_test_secret',
        'supports_refunds' => true,
        'fees_included_in_amount' => false,
        'fees_rate' => 2,
    ], $attributes));
}

it('refuses to handle subscription request when API credentials are missing', function () {
    ['subscription' => $subscription, 'client' => $client] = createGatewayTestSubscription('RAZORPAY', [
        'key_id' => null,
        'key_secret' => null,
    ]);
    $gateway = makeRazorpaySub(['key_id' => null, 'key_secret' => null]);

    $gateway->handleSubscriptionRequest(makeSubscriptionRequestDTO($subscription, $client), $subscription);
})->throws(Exception::class, 'Missing Razorpay API credentials.');

it('creates plan and subscription on Razorpay and returns checkout view url', function () {
    mockRazorpayHttp([
        // 1. Plan creation
        [
            'status' => 200,
            'body' => [
                'id' => 'plan_MOCK12345',
                'entity' => 'plan',
                'interval' => 1,
                'period' => 'monthly',
                'item' => [
                    'id' => 'item_123',
                    'name' => 'Monthly Plan',
                    'amount' => 100000,
                    'currency' => 'INR',
                ],
            ],
        ],
        // 2. Subscription creation
        [
            'status' => 200,
            'body' => [
                'id' => 'sub_MOCK67890',
                'entity' => 'subscription',
                'plan_id' => 'plan_MOCK12345',
                'status' => 'created',
                'total_count' => 120,
            ],
        ],
    ]);

    ['subscription' => $subscription, 'client' => $client] = createGatewayTestSubscription('RAZORPAY', [
        'key_id' => 'rzp_test_key',
        'key_secret' => 'rzp_test_secret',
    ]);
    $gateway = makeRazorpaySub();

    $dto = makeSubscriptionRequestDTO($subscription, $client);
    $url = $gateway->handleSubscriptionRequest($dto, $subscription);

    expect($url)->toContain(Razorpay::EMBEDDED_CHECKOUT_ENDPOINT)
        ->and($url)->toContain('subscription_id=sub_MOCK67890')
        ->and($url)->toContain('key_id=rzp_test_key')
        ->and($url)->toContain('callback_url=');

    $subscription->refresh();
    expect($subscription->subscription_id)->toBe('sub_MOCK67890')
        ->and($subscription->pg_reference_id)->toBe('sub_MOCK67890')
        ->and($subscription->plan_id)->toBe('plan_MOCK12345')
        ->and($subscription->status)->toBe(SubscriptionStatus::INITIALIZED);
});

it('builds subscription checkout url when subscription amount is a Money object directly', function () {
    ['subscription' => $subscription, 'client' => $client] = createGatewayTestSubscription('RAZORPAY', [
        'key_id' => 'rzp_test_key',
        'key_secret' => 'rzp_test_secret',
    ]);
    $subscription->amount = Money::of(500, 'INR');
    $gateway = makeRazorpaySub();

    $dto = makeSubscriptionRequestDTO($subscription, $client);
    $url = $gateway->buildSubscriptionCheckoutUrl($subscription, $dto);

    expect($url)->toContain(Razorpay::EMBEDDED_CHECKOUT_ENDPOINT)
        ->and($url)->toContain('amount=50000')
        ->and($url)->toContain('key_id=rzp_test_key');
});

it('renders the subscription checkout view with real connection and subscription details', function () {
    ['subscription' => $subscription] = createGatewayTestSubscription('RAZORPAY', [
        'key_id' => 'rzp_test_key_custom',
    ], [
        'subscription_id' => 'sub_VIEW12345',
        'plan_name' => 'Support ISKCON Monthly',
    ]);
    $gateway = makeRazorpaySub(['key_id' => 'rzp_test_key_custom']);

    $view = $gateway->subscriptionCheckoutForm($subscription);

    expect($view->getName())->toBe('razorpay.subscription-checkout')
        ->and($view->getData()['checkoutEndpoint'])->toBe(Razorpay::EMBEDDED_CHECKOUT_ENDPOINT)
        ->and($view->getData()['keyId'])->toBe('rzp_test_key_custom')
        ->and($view->getData()['subscriptionId'])->toBe('sub_VIEW12345')
        ->and($view->getData()['subscriptionDbId'])->toBe((string) $subscription->id)
        ->and($view->getData()['currency'])->toBe('INR')
        ->and($view->getData()['description'])->toBe('Support ISKCON Monthly')
        ->and($view->getData()['callbackUrl'])->toBe(route('handleSubscriptionResponse', [
            'pgClass' => 'RAZORPAY',
            'subscriptionDbId' => $subscription->id,
        ]))
        ->and($view->getData()['cancelUrl'])->toBe(route('handleSubscriptionResponse', [
            'pgClass' => 'RAZORPAY',
            'subscriptionDbId' => $subscription->id,
            'status' => 'cancelled',
        ]));
});

it('treats an explicitly cancelled subscription callback as cancelled without contacting Razorpay', function () {
    ['subscription' => $subscription] = createGatewayTestSubscription('RAZORPAY', []);
    $gateway = makeRazorpaySub();

    $response = $gateway->handleSubscriptionResponse([
        'subscriptionDbId' => (string) $subscription->id,
        'status' => 'cancelled',
    ]);

    expect($response->status)->toBe(SubscriptionStatus::CANCELLED);
    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::CANCELLED);
});

it('throws when the subscription referenced in the response does not exist', function () {
    $gateway = makeRazorpaySub();

    $gateway->handleSubscriptionResponse([
        'subscriptionDbId' => '999999999',
    ]);
})->throws(Exception::class, 'Subscription not found.');

it('verifies signature and updates status on subscription success response callback', function () {
    ['subscription' => $subscription] = createGatewayTestSubscription('RAZORPAY', [
        'key_id' => 'rzp_test_key',
        'key_secret' => 'rzp_test_secret',
    ], [
        'subscription_id' => 'sub_SUCCESS123',
    ]);

    $paymentId = 'pay_ABC123456';
    $subscriptionId = 'sub_SUCCESS123';
    $signature = hash_hmac('sha256', $paymentId.'|'.$subscriptionId, 'rzp_test_secret');

    mockRazorpayHttp([
        [
            'status' => 200,
            'body' => [
                'id' => $subscriptionId,
                'entity' => 'subscription',
                'status' => 'active',
                'token_id' => 'token_mandate_789',
                'charge_at' => time() + 86400 * 30,
                'current_start' => time(),
                'end_at' => time() + 86400 * 365,
            ],
        ],
    ]);

    $gateway = makeRazorpaySub();

    $response = $gateway->handleSubscriptionResponse([
        'subscriptionDbId' => (string) $subscription->id,
        'razorpay_payment_id' => $paymentId,
        'razorpay_subscription_id' => $subscriptionId,
        'razorpay_signature' => $signature,
    ]);

    expect($response->status)->toBe(SubscriptionStatus::ACTIVE)
        ->and($response->authorizationReference)->toBe('token_mandate_789')
        ->and($subscription->fresh()->status)->toBe(SubscriptionStatus::ACTIVE)
        ->and($subscription->fresh()->authorization_reference)->toBe('token_mandate_789');
});

it('fetches subscription status from Razorpay and maps lifecycle statuses', function () {
    ['subscription' => $subscription] = createGatewayTestSubscription('RAZORPAY', [
        'key_id' => 'rzp_test_key',
        'key_secret' => 'rzp_test_secret',
    ], [
        'subscription_id' => 'sub_STATUS123',
    ]);

    mockRazorpayHttp([
        [
            'status' => 200,
            'body' => [
                'id' => 'sub_STATUS123',
                'status' => 'authenticated',
                'token_id' => 'tok_999',
            ],
        ],
    ]);

    $gateway = makeRazorpaySub();
    $dto = $gateway->getSubscriptionStatus($subscription);

    expect($dto->status)->toBe(SubscriptionStatus::ACTIVE);
    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::ACTIVE);
});

it('manages Razorpay subscription lifecycle (pause, resume, cancel)', function () {
    ['subscription' => $subscription] = createGatewayTestSubscription('RAZORPAY', [
        'key_id' => 'rzp_test_key',
        'key_secret' => 'rzp_test_secret',
    ], [
        'subscription_id' => 'sub_MANAGE123',
        'status' => SubscriptionStatus::ACTIVE,
    ]);

    $gateway = makeRazorpaySub();

    // 1. Pause
    mockRazorpayHttp([
        ['status' => 200, 'body' => ['id' => 'sub_MANAGE123', 'status' => 'active']], // fetch
        ['status' => 200, 'body' => ['id' => 'sub_MANAGE123', 'status' => 'paused']], // pause
        ['status' => 200, 'body' => ['id' => 'sub_MANAGE123', 'status' => 'paused']], // getStatus fetch
    ]);
    $pauseDto = new SubscriptionManageDTO(action: SubscriptionAction::PAUSE);
    $res = $gateway->manageSubscription($subscription, $pauseDto);
    expect($res->status)->toBe(SubscriptionStatus::PAUSED);
    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::PAUSED);

    // 2. Resume
    mockRazorpayHttp([
        ['status' => 200, 'body' => ['id' => 'sub_MANAGE123', 'status' => 'paused']], // fetch
        ['status' => 200, 'body' => ['id' => 'sub_MANAGE123', 'status' => 'active']], // resume
        ['status' => 200, 'body' => ['id' => 'sub_MANAGE123', 'status' => 'active']], // getStatus fetch
    ]);
    $resumeDto = new SubscriptionManageDTO(action: SubscriptionAction::RESUME);
    $res = $gateway->manageSubscription($subscription, $resumeDto);
    expect($res->status)->toBe(SubscriptionStatus::ACTIVE);
    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::ACTIVE);

    // 3. Cancel
    mockRazorpayHttp([
        ['status' => 200, 'body' => ['id' => 'sub_MANAGE123', 'status' => 'active']], // fetch
        ['status' => 200, 'body' => ['id' => 'sub_MANAGE123', 'status' => 'cancelled']], // cancel
        ['status' => 200, 'body' => ['id' => 'sub_MANAGE123', 'status' => 'cancelled']], // getStatus fetch
    ]);
    $cancelDto = new SubscriptionManageDTO(action: SubscriptionAction::CANCEL);
    $res = $gateway->manageSubscription($subscription, $cancelDto);
    expect($res->status)->toBe(SubscriptionStatus::CANCELLED);
    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::CANCELLED);
});

it('creates addon recurring charge on Razorpay and records transaction', function () {
    ['subscription' => $subscription] = createGatewayTestSubscription('RAZORPAY', [
        'key_id' => 'rzp_test_key',
        'key_secret' => 'rzp_test_secret',
    ], [
        'subscription_id' => 'sub_CHARGE123',
        'status' => SubscriptionStatus::ACTIVE,
    ]);

    $charge = SubscriptionTransaction::create([
        'subscription_id' => $subscription->id,
        'client_id' => $subscription->client_id,
        'site_reference_id' => 'charge_rzp_1',
        'amount' => Money::of(500, 'INR'),
        'currency' => Currency::of('INR'),
        'status' => TransactionStatus::PENDING,
    ]);

    $chargeDto = new SubscriptionChargeRequestDTO(
        site_reference_id: $subscription->site_reference_id,
        charge_reference_id: 'charge_rzp_1',
        amount: 500,
        currency: 'INR',
        remarks: 'Monthly recurring donation'
    );

    mockRazorpayHttp([
        ['status' => 200, 'body' => ['id' => 'sub_CHARGE123', 'status' => 'active']], // fetch
        [
            'status' => 200,
            'body' => [
                'id' => 'ao_ADDON999',
                'entity' => 'addon',
                'item' => [
                    'amount' => 50000,
                    'currency' => 'INR',
                    'name' => 'Monthly recurring donation',
                ],
            ],
        ], // createAddon
    ]);

    $gateway = makeRazorpaySub();
    $res = $gateway->chargeSubscription($subscription, $charge, $chargeDto);

    expect($res->status)->toBe(TransactionStatus::SUCCESS)
        ->and($res->transactionId)->toBe('ao_ADDON999');

    $charge->refresh();
    expect($charge->status)->toBe(TransactionStatus::SUCCESS)
        ->and($charge->transaction_id)->toBe('ao_ADDON999');
});

it('retrieves charge status for an existing subscription transaction', function () {
    ['subscription' => $subscription] = createGatewayTestSubscription('RAZORPAY', []);

    $charge = SubscriptionTransaction::create([
        'subscription_id' => $subscription->id,
        'client_id' => $subscription->client_id,
        'site_reference_id' => 'charge_rzp_status',
        'transaction_id' => 'ao_EXISTING',
        'payment_id' => 'ao_EXISTING',
        'amount' => Money::of(250, 'INR'),
        'currency' => Currency::of('INR'),
        'status' => TransactionStatus::SUCCESS,
        'pg_fees' => Money::of(5, 'INR'),
    ]);

    $gateway = makeRazorpaySub();
    $dto = $gateway->getChargeStatus($charge->fresh());

    expect($dto->status)->toBe(TransactionStatus::SUCCESS)
        ->and($dto->transactionId)->toBe('ao_EXISTING')
        ->and($dto->chargeDbId)->toBe((string) $charge->id);
});

it('lists charges falling back to subscription transactions when invoices fetch errors', function () {
    ['subscription' => $subscription] = createGatewayTestSubscription('RAZORPAY', [], [
        'subscription_id' => 'sub_LIST123',
    ]);

    SubscriptionTransaction::create([
        'subscription_id' => $subscription->id,
        'client_id' => $subscription->client_id,
        'site_reference_id' => 'charge_local_1',
        'amount' => Money::of(100, 'INR'),
        'currency' => Currency::of('INR'),
        'status' => TransactionStatus::SUCCESS,
    ]);

    // No mock provided so it fails and catches Throwable, falling back to local transactions
    $gateway = makeRazorpaySub();
    $charges = $gateway->listCharges($subscription);

    expect($charges)->toHaveCount(1)
        ->and($charges[0]['site_reference_id'])->toBe('charge_local_1');
});

it('provides an actionable error message when Razorpay returns 401 unauthorized or array to string error on plan creation', function () {
    mockRazorpayHttp([
        [
            'status' => 401,
            'body' => '{"error":"Unauthorized"}',
        ],
    ]);

    ['subscription' => $subscription, 'client' => $client] = createGatewayTestSubscription('RAZORPAY', [
        'key_id' => 'rzp_test_key',
        'key_secret' => 'rzp_test_secret',
    ]);
    $gateway = makeRazorpaySub();

    $dto = makeSubscriptionRequestDTO($subscription, $client);
    $gateway->handleSubscriptionRequest($dto, $subscription);
})->throws(Exception::class, 'Razorpay Subscriptions / Plans is not authorized or enabled for this merchant account');
