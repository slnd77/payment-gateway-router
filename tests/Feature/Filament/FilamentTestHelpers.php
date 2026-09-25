<?php

/**
 * Shared fixtures for the Filament resource "page visit" tests in this
 * directory. Every *ResourceTest.php here requires this file explicitly
 * (Pest discovers tests by filename, not directory), mirroring the pattern
 * used by tests/Feature/PaymentGateways/GatewayTestHelpers.php.
 *
 * These tests only visit resource pages (index/create/edit/view) - they
 * don't fill in or submit forms, click table actions, or perform
 * create/update/delete. That means Filament's eagerly-evaluated Form/Table/
 * Infolist schema closures get exercised on render, but code that only runs
 * on form submission (e.g. the ValidatesGatewayAttributes/
 * ValidatesClientConnection concerns) is out of scope by design.
 */

use App\Enums\ConnectionType;
use App\Models\Client;
use App\Models\ClientConnection;
use App\Models\ClientCustomer;
use App\Models\PGConnection;
use App\Models\Subscription;
use App\Models\SubscriptionTransaction;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SupportedPGSeeder;
use Devhammed\LaravelBrickMoney\Currency;
use Devhammed\LaravelBrickMoney\Money;
use Illuminate\Support\Str;

/**
 * Seeds roles/permissions and the supported-gateway catalogue (both needed
 * by the resource forms/policies), then creates and returns a superadmin
 * user - every seeded role ends up with every permission (see
 * RolesAndPermissionsSeeder), so "superadmin" here is just a stable choice.
 */
function actingAsFilamentAdmin(): User
{
    (new RolesAndPermissionsSeeder)->run();
    (new SupportedPGSeeder)->run();

    $user = User::factory()->create();
    $user->assignRole('superadmin');

    test()->actingAs($user);

    return $user;
}

function makeFilamentTestClient(): Client
{
    $user = User::factory()->create();

    return Client::create([
        'uuid' => (string) Str::ulid(),
        'name' => 'Filament Test Client',
        'client_id' => Str::upper(Str::random(16)),
        'client_secret' => Str::random(40),
        'website' => 'https://example.test',
        'redirect_uri' => 'https://example.test/callback',
        'redirect_uri_separator' => '?',
        'status' => true,
        'user_id' => $user->id,
    ]);
}

function makeFilamentTestPgConnection(string $pgClass = 'PGSimulator'): PGConnection
{
    return PGConnection::create([
        'name' => $pgClass.' Connection',
        'pg_class' => $pgClass,
        'attributes' => [],
        'status' => true,
        'type' => ConnectionType::TEST,
    ]);
}

function makeFilamentTestClientConnection(?Client $client = null, ?PGConnection $pgConnection = null, bool $isRecurring = false): ClientConnection
{
    return ClientConnection::create([
        'client_id' => ($client ?? makeFilamentTestClient())->id,
        'pg_connection_id' => ($pgConnection ?? makeFilamentTestPgConnection())->id,
        'is_recurring' => $isRecurring,
        'type' => ConnectionType::TEST,
        'status' => true,
    ]);
}

function makeFilamentTestTransaction(\App\Enums\TransactionStatus $status = \App\Enums\TransactionStatus::SUCCESS): Transaction
{
    $client = makeFilamentTestClient();
    $pgConnection = makeFilamentTestPgConnection();

    $customer = ClientCustomer::create([
        'client_id' => $client->id,
        'uuid' => (string) Str::ulid(),
        'name' => 'Jane Doe',
        'email' => 'jane@example.test',
        'mobile' => '9876543210',
    ]);

    $amount = Money::of(100, 'INR');

    $transaction = $customer->transactions()->create([
        'client_id' => $client->id,
        'site_reference_id' => 'ref-'.Str::random(12),
        'pg_connection_id' => $pgConnection->id,
        'amount' => $amount,
        'currency' => Currency::of('INR'),
        'transaction_amount' => $amount,
        'status' => $status,
        'request_data' => [],
    ]);

    return $transaction->refresh();
}

function makeFilamentTestSubscription(): Subscription
{
    $client = makeFilamentTestClient();
    $pgConnection = makeFilamentTestPgConnection();

    $customer = ClientCustomer::create([
        'client_id' => $client->id,
        'uuid' => (string) Str::ulid(),
        'name' => 'Jane Doe',
        'email' => 'jane@example.test',
        'mobile' => '9876543210',
    ]);

    return Subscription::create([
        'client_id' => $client->id,
        'client_customer_id' => $customer->id,
        'pg_connection_id' => $pgConnection->id,
        'subscription_type' => 'subscription',
        'site_reference_id' => 'ref-'.Str::random(12),
        'subscription_id' => 'SUB-'.Str::random(12),
        'plan_id' => 'PLAN-1',
        'start_date_time' => now(),
        'end_date_time' => now()->addYear(),
        'period' => 'monthly',
        'interval' => 1,
        'amount' => Money::of(10, 'INR'),
        'currency' => Currency::of('INR'),
        'status' => 'ACTIVE',
    ]);
}

function makeFilamentTestSubscriptionTransaction(): SubscriptionTransaction
{
    $subscription = makeFilamentTestSubscription();

    return SubscriptionTransaction::create([
        'subscription_id' => $subscription->id,
        'transaction_id' => 'TXN-'.Str::random(12),
        'amount' => Money::of(10, 'INR'),
        'currency' => Currency::of('INR'),
        'status' => 'SUCCESS',
        'payment_method' => 'card',
        'pg_fees' => Money::of(0, 'INR'),
        'pg_tax' => Money::of(0, 'INR'),
        'data' => [],
        'transaction_date_time' => now(),
    ]);
}
