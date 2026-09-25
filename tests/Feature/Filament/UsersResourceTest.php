<?php

use App\Filament\Resources\Users\UsersResource;
use App\Models\User;

require_once __DIR__.'/FilamentTestHelpers.php';

it('visits the users list, create, and edit pages', function () {
    actingAsFilamentAdmin();
    $user = User::factory()->create();

    $this->get(UsersResource::getUrl('index'))->assertOk();
    $this->get(UsersResource::getUrl('create'))->assertOk();
    $this->get(UsersResource::getUrl('edit', ['record' => $user]))->assertOk();
});
