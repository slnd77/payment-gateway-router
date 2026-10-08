<?php

use App\Http\Controllers\API\V1\PaymentController;
use App\Http\Controllers\API\V1\SubscriptionController;
use App\Http\Middleware\HandleApiClientEncryptedRequest;
use App\Http\Middleware\HandleApiRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');*/

Route::group(['middleware' => [HandleApiClientEncryptedRequest::class]], function () {
    Route::get('/initPayment', [PaymentController::class, 'initiatePayment'])
        ->name('initPayment');
    Route::get('/initSubscription', [SubscriptionController::class, 'initiateSubscription'])
        ->name('initSubscription');
});

Route::group(['middleware' => [HandleApiRequest::class]], function () {
    Route::get('/transaction/{reference_id}', [PaymentController::class, 'getTransactionDetails'])
        ->name('transaction.details');

    Route::get('/transactions', [PaymentController::class, 'getTransactions'])
        ->name('transactions.list');

    Route::get('/subscriptions', [SubscriptionController::class, 'index'])
        ->name('subscriptions.list');

    Route::get('/subscription/{reference_id}', [SubscriptionController::class, 'show'])
        ->name('subscription.details');

    Route::post('/subscription/{reference_id}/manage', [SubscriptionController::class, 'manage'])
        ->name('subscription.manage');

    Route::post('/subscription/{reference_id}/charge', [SubscriptionController::class, 'charge'])
        ->name('subscription.charge');

    Route::get('/subscription/{reference_id}/charges', [SubscriptionController::class, 'charges'])
        ->name('subscription.charges.list');

    Route::get('/subscription/{reference_id}/charges/{charge_reference_id}', [SubscriptionController::class, 'chargeDetails'])
        ->name('subscription.charges.details');
});

Route::any('/handleResponse/{pgClass}', [PaymentController::class, 'handlePaymentResponse'])->name('handlePaymentResponse');
Route::any('/handleSubscriptionResponse/{pgClass}', [SubscriptionController::class, 'handleSubscriptionResponse'])->name('handleSubscriptionResponse');
