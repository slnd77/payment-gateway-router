<?php

use App\Filament\Resources\SubscriptionTransactions\SubscriptionTransactionResource;

require_once __DIR__.'/FilamentTestHelpers.php';

it('visits the subscription transactions list and view pages', function () {
    actingAsFilamentAdmin();
    $subscriptionTransaction = makeFilamentTestSubscriptionTransaction();

    $this->get(SubscriptionTransactionResource::getUrl('index'))->assertOk();
    $this->get(SubscriptionTransactionResource::getUrl('view', ['record' => $subscriptionTransaction]))->assertOk();
});

it('denies create and edit - SubscriptionTransactionPolicy always refuses them by design', function () {
    actingAsFilamentAdmin();
    $subscriptionTransaction = makeFilamentTestSubscriptionTransaction();

    $this->get(SubscriptionTransactionResource::getUrl('create'))->assertForbidden();
    $this->get(SubscriptionTransactionResource::getUrl('edit', ['record' => $subscriptionTransaction]))->assertForbidden();
});
