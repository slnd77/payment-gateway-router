<?php

use App\Classes\PaymentGateways\Cashfree;
use App\Classes\PaymentGateways\ICICI;
use App\Classes\PaymentGateways\PayPal;
use App\Classes\PaymentGateways\PaymentGatewayFactory;
use App\Classes\PaymentGateways\PayU;
use App\Classes\PaymentGateways\PGSimulator;
use App\Classes\PaymentGateways\Razorpay;
use App\Classes\PaymentGateways\Stripe;
use App\Enums\ConnectionType;

it('creates a fully configured gateway instance for every supported pg_class', function (string $pgClass, string $expectedClass) {
    $gateway = PaymentGatewayFactory::create([
        'pg_class' => $pgClass,
        'attributes' => [],
        'type' => ConnectionType::TEST,
    ]);

    expect($gateway)->toBeInstanceOf($expectedClass);
})->with([
    ['ICICI', ICICI::class],
    ['PGSimulator', PGSimulator::class],
    ['RAZORPAY', Razorpay::class],
    ['CASHFREE', Cashfree::class],
    ['PAYPAL', PayPal::class],
    ['STRIPE', Stripe::class],
    ['PAYU', PayU::class],
]);

it('throws for an unrecognised pg_class in create()', function () {
    PaymentGatewayFactory::create([
        'pg_class' => 'NOT_A_REAL_GATEWAY',
        'attributes' => [],
        'type' => ConnectionType::TEST,
    ]);
})->throws(Exception::class, 'Invalid payment gateway type.');

it('creates an empty (credential-less) gateway instance for every supported pg_class', function (string $pgClass, string $expectedClass) {
    $gateway = PaymentGatewayFactory::createEmpty($pgClass);

    expect($gateway)->toBeInstanceOf($expectedClass);
})->with([
    ['ICICI', ICICI::class],
    ['PGSimulator', PGSimulator::class],
    ['RAZORPAY', Razorpay::class],
    ['CASHFREE', Cashfree::class],
    ['PAYPAL', PayPal::class],
    ['STRIPE', Stripe::class],
    ['PAYU', PayU::class],
]);

it('throws for an unrecognised pg_class in createEmpty()', function () {
    PaymentGatewayFactory::createEmpty('NOT_A_REAL_GATEWAY');
})->throws(Exception::class, 'Invalid payment gateway type.');
