<?php

use App\Filament\Resources\Subscriptions\SubscriptionsResource;

require_once __DIR__.'/FilamentTestHelpers.php';

it('visits the subscriptions list page', function () {
    actingAsFilamentAdmin();
    makeFilamentTestSubscription();

    $this->get(SubscriptionsResource::getUrl('index'))->assertOk();
});

it('denies create and edit - SubscriptionPolicy always refuses them by design', function () {
    actingAsFilamentAdmin();
    $subscription = makeFilamentTestSubscription();

    $this->get(SubscriptionsResource::getUrl('create'))->assertForbidden();
    $this->get(SubscriptionsResource::getUrl('edit', ['record' => $subscription]))->assertForbidden();
});
