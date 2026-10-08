<?php

namespace App\Classes\PaymentGateways;

use App\Contracts\PaymentGatewayInterface;
use App\Contracts\SubscriptionGatewayInterface;

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
            'PAYPAL' => new PayPal($connection['attributes'], $connection['type']),
            'STRIPE' => new Stripe($connection['attributes'], $connection['type']),
            'PAYU' => new PayU($connection['attributes'], $connection['type']),
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
            'PAYPAL' => new PayPal([]),
            'STRIPE' => new Stripe([]),
            'PAYU' => new PayU([]),
            default => throw new \Exception('Invalid payment gateway type.'),
        };
    }

    /**
     * @param  array<string, mixed>  $connection
     */
    public static function createSubscriptionGateway(array $connection): SubscriptionGatewayInterface
    {
        $gateway = self::create($connection);

        if (! $gateway instanceof SubscriptionGatewayInterface) {
            $pgClass = $connection['pg_class'] ?? 'unknown';
            throw new \Exception("Recurring payments are not supported by {$pgClass}.");
        }

        return $gateway;
    }

    public static function createEmptySubscriptionGateway(string $pg_class): SubscriptionGatewayInterface
    {
        $gateway = self::createEmpty($pg_class);

        if (! $gateway instanceof SubscriptionGatewayInterface) {
            throw new \Exception("Recurring payments are not supported by {$pg_class}.");
        }

        return $gateway;
    }
}
