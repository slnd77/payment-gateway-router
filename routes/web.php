<?php

use App\Http\Controllers\PGSimulatorController;
use App\Http\Controllers\UtilsController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');
Route::get("testPayment",[ UtilsController::class,'testPayment'])->name('testPayment');
Route::get('pg-simulator/checkout/{transaction}', [PGSimulatorController::class, 'checkout'])->name('pgSimulatorCheckout');

if (app()->environment('testing')) {
    require __DIR__.'/testing.php';
}
