<?php

use App\Filament\Resources\Subscriptions\Pages\ViewSubscriptions;
use App\Filament\Resources\Subscriptions\RelationManagers\TransactionsRelationManager;
use App\Filament\Resources\Subscriptions\SubscriptionsResource;
use Livewire\Livewire;

require_once __DIR__.'/FilamentTestHelpers.php';

it('visits the subscriptions list and view pages', function () {
    actingAsFilamentAdmin();
    $subscription = makeFilamentTestSubscription();

    $this->get(SubscriptionsResource::getUrl('index'))->assertOk();
    $this->get(SubscriptionsResource::getUrl('view', ['record' => $subscription]))->assertOk();
});

it('denies create and edit - SubscriptionPolicy always refuses them by design', function () {
    actingAsFilamentAdmin();
    $subscription = makeFilamentTestSubscription();

    $this->get(SubscriptionsResource::getUrl('create'))->assertForbidden();
    $this->get(SubscriptionsResource::getUrl('edit', ['record' => $subscription]))->assertForbidden();
});

it('lists the subscription\'s transactions on its view page', function () {
    actingAsFilamentAdmin();
    $transaction = makeFilamentTestSubscriptionTransaction();
    $other = makeFilamentTestSubscriptionTransaction();

    $this->get(SubscriptionsResource::getUrl('view', ['record' => $transaction->subscription]))
        ->assertOk()
        ->assertSeeLivewire(TransactionsRelationManager::class);

    Livewire::test(TransactionsRelationManager::class, [
        'ownerRecord' => $transaction->subscription,
        'pageClass' => ViewSubscriptions::class,
    ])
        ->assertOk()
        ->assertCanSeeTableRecords([$transaction])
        ->assertCanNotSeeTableRecords([$other]);
});

it('shows transactions count badge on relation manager and infolist', function () {
    actingAsFilamentAdmin();
    $transaction = makeFilamentTestSubscriptionTransaction();

    expect(TransactionsRelationManager::getBadge($transaction->subscription, ViewSubscriptions::class))
        ->toBe('1');

    $this->get(SubscriptionsResource::getUrl('view', ['record' => $transaction->subscription]))
        ->assertOk()
        ->assertSee('Total Transactions');
});
