<?php

use App\Classes\PaymentGateways\Cashfree;
use App\Classes\PaymentGateways\Razorpay;
use App\Http\Controllers\PGSimulatorController;
use App\Http\Controllers\UtilsController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');
Route::get('testPayment', [UtilsController::class, 'testPayment'])->name('testPayment');
Route::get('pg-simulator/checkout/{transaction}', [PGSimulatorController::class, 'checkout'])->name('pgSimulatorCheckout');
Route::get('razorpay/checkout/{transaction}/{order}', [Razorpay::class, 'checkoutForm'])->name('razorpayEmbeddedCheckout');
Route::get('cashfree/checkout/{transaction}/{session}', [Cashfree::class, 'checkoutForm'])->name('cashfreeEmbeddedCheckout');

if (app()->environment('testing')) {
    require __DIR__.'/testing.php';
}
