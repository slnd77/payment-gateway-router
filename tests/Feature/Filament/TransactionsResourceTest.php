<?php

use App\Enums\TransactionStatus;
use App\Filament\Resources\Transactions\TransactionsResource;

require_once __DIR__.'/FilamentTestHelpers.php';

it('visits the transactions list and view pages', function () {
    actingAsFilamentAdmin();
    $transaction = makeFilamentTestTransaction();

    $this->get(TransactionsResource::getUrl('index'))->assertOk();
    $this->get(TransactionsResource::getUrl('view', ['record' => $transaction]))->assertOk();
});

it('renders every transaction status badge colour on the list and view pages', function (TransactionStatus $status) {
    actingAsFilamentAdmin();
    $transaction = makeFilamentTestTransaction($status);

    $this->get(TransactionsResource::getUrl('index'))->assertOk();
    $this->get(TransactionsResource::getUrl('view', ['record' => $transaction]))->assertOk();
})->with([
    TransactionStatus::FAILED,
    TransactionStatus::PENDING,
    TransactionStatus::PROCESSING,
    TransactionStatus::CANCELLED,
    TransactionStatus::REFUNDED,
]);

it('denies create and edit - TransactionPolicy always refuses them by design', function () {
    actingAsFilamentAdmin();
    $transaction = makeFilamentTestTransaction();

    $this->get(TransactionsResource::getUrl('create'))->assertForbidden();
    $this->get(TransactionsResource::getUrl('edit', ['record' => $transaction]))->assertForbidden();
});
