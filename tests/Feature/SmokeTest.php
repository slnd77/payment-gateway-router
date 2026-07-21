<?php

use App\Models\SupportedPaymentGateway;
use Database\Seeders\SupportedPGSeeder;

test('the health check endpoint responds', function () {
    $this->get('/up')->assertOk();
});

test('the home route redirects to the dashboard', function () {
    $this->get(route('home'))->assertRedirect('/dashboard');
});

test('guests are redirected to the dashboard login page', function () {
    $this->get('/dashboard')->assertRedirect('/dashboard/login');
});

test('the supported payment gateway seeder registers ICICI and PGSimulator', function () {
    (new SupportedPGSeeder)->run();

    expect(SupportedPaymentGateway::where('name', 'ICICI')->exists())->toBeTrue()
        ->and(SupportedPaymentGateway::where('name', 'PGSimulator')->exists())->toBeTrue();
});
