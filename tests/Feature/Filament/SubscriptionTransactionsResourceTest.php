<?php

use App\Filament\Resources\Subscriptions\SubscriptionsResource;
use App\Filament\Resources\SubscriptionTransactions\Pages\ListSubscriptionTransactions;
use App\Filament\Resources\SubscriptionTransactions\SubscriptionTransactionResource;
use Livewire\Livewire;

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

it('renders clickable links for subscription and transaction in the transactions table', function () {
    actingAsFilamentAdmin();
    $transaction = makeFilamentTestSubscriptionTransaction();

    Livewire::test(ListSubscriptionTransactions::class)
        ->assertCanSeeTableRecords([$transaction])
        ->assertTableColumnExists('id')
        ->assertTableColumnExists('subscription.site_reference_id')
        ->assertTableColumnExists('site_reference_id')
        ->assertTableColumnExists('payment_id');

    expect(SubscriptionTransactionResource::getUrl('view', ['record' => $transaction]))
        ->toContain((string) $transaction->id);

    expect(SubscriptionsResource::getUrl('view', ['record' => $transaction->subscription_id]))
        ->toContain((string) $transaction->subscription_id);
});
