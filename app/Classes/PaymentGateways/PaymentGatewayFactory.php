<?php

namespace App\Classes\PaymentGateways;

use App\Contracts\PaymentGatewayInterface;

class PaymentGatewayFactory
{
    /**
     * @param  array<string, mixed>  $connection
     */
    public static function create(array $connection): PaymentGatewayInterface
    {
        return match ($connection['pg_class']) {
            'ICICI' => new ICICI($connection['attributes'], $connection['type']),
            'PGSimulator' => new PGSimulator($connection['attributes'], $connection['type']),
            'RAZORPAY' => new Razorpay($connection['attributes'], $connection['type']),
            'CASHFREE' => new Cashfree($connection['attributes'], $connection['type']),
            default => throw new \Exception('Invalid payment gateway type.'),
        };
    }

    public static function createEmpty(string $pg_class): PaymentGatewayInterface
    {
        return match ($pg_class) {
            'ICICI' => new ICICI([]),
            'PGSimulator' => new PGSimulator([]),
            'RAZORPAY' => new Razorpay([]),
            'CASHFREE' => new Cashfree([]),
            default => throw new \Exception('Invalid payment gateway type.'),
        };
    }
}
