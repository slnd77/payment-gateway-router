<?php

namespace Database\Seeders;

use App\Models\SupportedPaymentGateway;
use Illuminate\Database\Seeder;

class SupportedPGSeeder extends Seeder
{
    public function run(): void
    {
        echo "Running SupportedPGSeeder\n";
        $supportedPaymentGateways = [
            'ICICI' => ['pg_class'=> 'ICICI',
                'required' => [
                    'merchant_id' => 'string',
                    'aggregator_id' => 'string',
                    'supports_refunds' => 'boolean',
                    'encryption_key' => 'string',
                    'sub_merchant_id' => 'string',
                    'paymode' => 'string',
                    'fees_included_in_amount' => 'boolean',
                    'fees_rate' => 'float',
            ]],
            'RAZORPAY' => [
                'pg_class'=> 'RAZORPAY',
                'required' => [
                    'supports_refunds' => 'boolean',
                    'key_id' => 'string',
                    'key_secret' => 'string',
                    'fees_included_in_amount' => 'boolean',
                    'fees_rate' => 'float',
            ]],
            'PGSimulator' => [
                'pg_class'=> 'PGSimulator',
                'required' => [
                    'supports_refunds' => 'boolean',
                    'fees_included_in_amount' => 'boolean',
                    'fees_rate' => 'float',
            ]],
            'PAYPAL' => [
                'pg_class'=> 'PAYPAL',
                'required' => [
                    'mode' => 'radio|live,sandbox',
                    'paymentAction' => 'string|Sale',
                    'locale' => 'string|en_US',
                    'validate_ssl' => 'boolean',
                    'currency' => 'string|USD',
                    'client_id' => 'string',
                    'secret' => 'string',
                    'supports_refunds' => 'boolean',
                    'fees_included_in_amount' => 'boolean',
                    'fees_rate' => 'float',
                    'return_url' => 'string',
            ]],
            'CASHFREE' => [
                'pg_class'=> 'CASHFREE',
                'required' => [
                    'supports_refunds' => 'boolean',
                    'key_id' => 'string',
                    'key_secret' => 'string',
                    'fees_included_in_amount' => 'boolean',
                    'fees_rate' => 'float',
            ]],
            /*'STRIPE' => ['required' => [
                'supports_refunds' => 'boolean',
                'key_id' => 'string',
                'key_secret' => 'string',
                'fees_included_in_amount' => 'boolean',
                'fees_rate' => 'float',
            ]],*/
        ];

        foreach ($supportedPaymentGateways as $name => $options) {
            $pg = SupportedPaymentGateway::updateOrCreate(
                ['name' => $name],
                ['name' => $name, 'pg_class' => $options['pg_class'], 'attributes' => $options]
            );
            echo $name . " inserted \n";
        }
        echo "Ran SupportedPGSeeder\n";
    }
}
