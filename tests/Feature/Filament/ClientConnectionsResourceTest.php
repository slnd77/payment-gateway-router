<?php

use App\Enums\ConnectionType;
use App\Filament\Resources\ClientConnections\ClientConnectionsResource;
use App\Filament\Resources\ClientConnections\Pages\CreateClientConnections;
use App\Filament\Resources\ClientConnections\Pages\EditClientConnections;
use App\Models\ClientConnection;
use Livewire\Livewire;

require_once __DIR__.'/FilamentTestHelpers.php';

it('visits the client connection list, create, view, and edit pages', function () {
    actingAsFilamentAdmin();
    $clientConnection = makeFilamentTestClientConnection();

    $this->get(ClientConnectionsResource::getUrl('index'))->assertOk();
    $this->get(ClientConnectionsResource::getUrl('create'))->assertOk();
    $this->get(ClientConnectionsResource::getUrl('view', ['record' => $clientConnection]))->assertOk();
    $this->get(ClientConnectionsResource::getUrl('edit', ['record' => $clientConnection]))->assertOk();
});

it('lists both recurring and one-time connections (table testConnection link covers both transaction types)', function () {
    actingAsFilamentAdmin();
    makeFilamentTestClientConnection(isRecurring: false);
    makeFilamentTestClientConnection(isRecurring: true);

    $this->get(ClientConnectionsResource::getUrl('index'))->assertOk();
});

/*
|--------------------------------------------------------------------------
| Form-submission tests
|--------------------------------------------------------------------------
|
| Everything else in this directory only visits pages. These go further
| and actually submit the create/edit forms, because
| ValidatesClientConnection::validateClientConnection() (environment
| match, transaction-type, and one-active-connection-per-client checks)
| only runs on a real create/save call - no page visit reaches it.
*/

it('creates a client connection when validation passes', function () {
    actingAsFilamentAdmin();
    $client = makeFilamentTestClient();
    $pgConnection = makeFilamentTestPgConnection();

    Livewire::test(CreateClientConnections::class)
        ->fillForm([
            'client_id' => $client->id,
            'pg_connection_id' => $pgConnection->id,
            'transaction_type' => 'sale',
            'type' => ConnectionType::TEST->value,
            'status' => true,
            'is_recurring' => false,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(ClientConnection::where('client_id', $client->id)->exists())->toBeTrue();
});

it('refuses to create a client connection whose environment does not match its gateway connection', function () {
    actingAsFilamentAdmin();
    $client = makeFilamentTestClient();
    $pgConnection = makeFilamentTestPgConnection(); // ConnectionType::TEST

    Livewire::test(CreateClientConnections::class)
        ->fillForm([
            'client_id' => $client->id,
            'pg_connection_id' => $pgConnection->id,
            'transaction_type' => 'sale',
            'type' => ConnectionType::PRODUCTION->value,
            'status' => true,
        ])
        ->call('create')
        ->assertNotified('Invalid client connection');

    expect(ClientConnection::where('client_id', $client->id)->exists())->toBeFalse();
});

it('allows a client to have both an active one-time connection and an active recurring connection', function () {
    actingAsFilamentAdmin();
    $client = makeFilamentTestClient();
    $pgConnection1 = makeFilamentTestPgConnection('PGSimulator');
    $pgConnection2 = makeFilamentTestPgConnection('Razorpay');

    // Create first active connection: one-time (is_recurring: false)
    makeFilamentTestClientConnection($client, $pgConnection1, isRecurring: false);

    // Create second active connection for the same client: recurring (is_recurring: true)
    Livewire::test(CreateClientConnections::class)
        ->fillForm([
            'client_id' => $client->id,
            'pg_connection_id' => $pgConnection2->id,
            'transaction_type' => 'sale',
            'type' => ConnectionType::TEST->value,
            'status' => true,
            'is_recurring' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(ClientConnection::where('client_id', $client->id)->where('status', true)->count())->toBe(2);
});

it('refuses to save an edited client connection when it would exceed the one-active-connection limit for one-time connections', function () {
    actingAsFilamentAdmin();
    $client = makeFilamentTestClient();
    $pgConnection = makeFilamentTestPgConnection();
    makeFilamentTestClientConnection($client, $pgConnection, isRecurring: false); // already active by default
    $secondConnection = makeFilamentTestClientConnection($client, $pgConnection, isRecurring: false);

    Livewire::test(EditClientConnections::class, ['record' => $secondConnection->getRouteKey()])
        ->fillForm([
            'client_id' => $client->id,
            'pg_connection_id' => $pgConnection->id,
            'transaction_type' => 'sale',
            'type' => ConnectionType::TEST->value,
            'status' => true,
            'is_recurring' => false,
        ])
        ->call('save')
        ->assertNotified('Invalid client connection');
});

it('refuses to save a second active recurring connection for the same client', function () {
    actingAsFilamentAdmin();
    $client = makeFilamentTestClient();
    $pgConnection = makeFilamentTestPgConnection();
    makeFilamentTestClientConnection($client, $pgConnection, isRecurring: true);
    $secondRecurringConnection = makeFilamentTestClientConnection($client, $pgConnection, isRecurring: true);

    Livewire::test(EditClientConnections::class, ['record' => $secondRecurringConnection->getRouteKey()])
        ->fillForm([
            'client_id' => $client->id,
            'pg_connection_id' => $pgConnection->id,
            'transaction_type' => 'sale',
            'type' => ConnectionType::TEST->value,
            'status' => true,
            'is_recurring' => true,
        ])
        ->call('save')
        ->assertNotified('Invalid client connection');
});
