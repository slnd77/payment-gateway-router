<?php

use App\Classes\PaymentGateways\Cashfree;
use App\Classes\PaymentGateways\PayU;
use App\Classes\PaymentGateways\Razorpay;
use App\Http\Controllers\DemoController;
use App\Http\Controllers\PGSimulatorController;
use App\Http\Controllers\UtilsController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', config('demo.enabled') ? '/demo' : '/dashboard')->name('home');
Route::get('testPayment', [UtilsController::class, 'testPayment'])->name('testPayment');
Route::get('pg-simulator/checkout/{transaction}', [PGSimulatorController::class, 'checkout'])->name('pgSimulatorCheckout');
Route::get('razorpay/checkout/{transaction}/{order}', [Razorpay::class, 'checkoutForm'])->name('razorpayEmbeddedCheckout');
Route::get('razorpay/subscription/checkout/{subscription}', [Razorpay::class, 'subscriptionCheckoutForm'])->name('razorpaySubscriptionCheckout');
Route::get('cashfree/checkout/{transaction}/{session}', [Cashfree::class, 'checkoutForm'])->name('cashfreeEmbeddedCheckout');
Route::get('cashfree/subscription/checkout/{subscription}/{session}', [Cashfree::class, 'subscriptionCheckoutForm'])->name('cashfreeSubscriptionCheckout');
Route::get('payu/checkout/{transaction}', [PayU::class, 'checkoutForm'])->name('payuCheckout');
Route::get('pg-simulator/subscription/checkout/{subscription}', [PGSimulatorController::class, 'subscriptionCheckout'])->name('pgSimulatorSubscriptionCheckout');

Route::middleware('throttle:30,1')->group(function () {
    Route::get('demo', [DemoController::class, 'index'])->name('demo.index');
    Route::post('demo/pay', [DemoController::class, 'pay'])->name('demo.pay');
    Route::get('demo/return/{gateway}', [DemoController::class, 'return'])->name('demo.return');
});

if (app()->environment('testing')) {
    require __DIR__.'/testing.php';
}
