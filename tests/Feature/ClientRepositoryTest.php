<?php

use App\Models\Client;
use App\Models\User;
use App\Repositories\ClientRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The array store used in tests keeps values in memory by default, which hides
 * serialization bugs. Serializing values makes it behave like the database
 * store used in production, including the cache.serializable_classes = false
 * restriction that turns cached objects into __PHP_Incomplete_Class.
 */
beforeEach(function () {
    config(['cache.stores.array.serialize' => true]);
    Cache::forgetDriver('array');
});

function makeRepositoryTestClient(): Client
{
    return Client::create([
        'uuid' => (string) Str::ulid(),
        'name' => 'Repository Test Client',
        'client_id' => Str::upper(Str::random(16)),
        'client_secret' => Str::random(40),
        'website' => 'https://example.test',
        'redirect_uri' => 'https://example.test/return',
        'redirect_uri_separator' => '?',
        'status' => true,
        'data' => ['foo' => 'bar'],
        'user_id' => User::factory()->create()->id,
    ]);
}

it('returns the client from a cold and a warm cache', function () {
    $client = makeRepositoryTestClient();
    $repository = app(ClientRepository::class);

    $cold = $repository->getClient($client->id);
    $warm = $repository->getClient($client->id);

    foreach ([$cold, $warm] as $result) {
        expect($result)->toBeInstanceOf(Client::class)
            ->and($result->exists)->toBeTrue()
            ->and($result->id)->toBe($client->id)
            ->and($result->client_id)->toBe($client->client_id)
            ->and($result->status)->toBeTrue()
            ->and($result->data)->toBe(['foo' => 'bar']);
    }
});

it('returns null for an unknown client', function () {
    expect(app(ClientRepository::class)->getClient(999999))->toBeNull();
});

it('serves fresh data after the client is updated', function () {
    $client = makeRepositoryTestClient();
    $repository = app(ClientRepository::class);

    $repository->getClient($client->id);
    $client->update(['name' => 'Renamed Client']);

    expect($repository->getClient($client->id)->name)->toBe('Renamed Client');
});
