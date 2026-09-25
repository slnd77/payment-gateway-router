<?php

use App\Filament\Resources\Clients\ClientsResource;
use App\Filament\Resources\Clients\Pages\CreateClients;
use App\Filament\Resources\Clients\Pages\EditClients;
use App\Models\User;
use Livewire\Livewire;

require_once __DIR__.'/FilamentTestHelpers.php';

it('visits the clients list, create, view, and edit pages', function () {
    actingAsFilamentAdmin();
    $client = makeFilamentTestClient();

    $this->get(ClientsResource::getUrl('index'))->assertOk();
    $this->get(ClientsResource::getUrl('create'))->assertOk();
    $this->get(ClientsResource::getUrl('view', ['record' => $client]))->assertOk();
    $this->get(ClientsResource::getUrl('edit', ['record' => $client]))->assertOk();
});

/*
|--------------------------------------------------------------------------
| Form-submission tests
|--------------------------------------------------------------------------
|
| Everything else in this directory only visits pages. These two go
| further and actually submit the create/edit forms, because
| CreateClients::afterCreate() / EditClients::afterSave() (granting
| "can_view_client" to the client's selected users) only run on a real
| create/save call - no page visit reaches them.
*/

it('creates a client and grants its selected users the can_view_client permission', function () {
    actingAsFilamentAdmin();
    $viewerUser = User::factory()->create();
    $viewerUser->assignRole('user');

    Livewire::test(CreateClients::class)
        ->fillForm([
            'name' => 'New Client',
            'status' => true,
            'users' => [$viewerUser->id],
            'website' => 'https://example.test',
            'redirect_uri' => 'https://example.test/callback',
            'redirect_uri_separator' => '?',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect($viewerUser->fresh()->can('can_view_client'))->toBeTrue();
});

it('saving an edited client grants its selected users the can_view_client permission', function () {
    actingAsFilamentAdmin();
    $client = makeFilamentTestClient();
    $viewerUser = User::factory()->create();
    $viewerUser->assignRole('user');

    Livewire::test(EditClients::class, ['record' => $client->getRouteKey()])
        ->fillForm(['users' => [$viewerUser->id]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($viewerUser->fresh()->can('can_view_client'))->toBeTrue();
});
