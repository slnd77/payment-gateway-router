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

it('refuses to save an edited client connection when it would exceed the one-active-connection limit', function () {
    actingAsFilamentAdmin();
    $client = makeFilamentTestClient();
    $pgConnection = makeFilamentTestPgConnection();
    makeFilamentTestClientConnection($client, $pgConnection); // already active by default
    $secondConnection = makeFilamentTestClientConnection($client, $pgConnection);

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
