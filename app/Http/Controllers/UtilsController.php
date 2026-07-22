<?php

namespace App\Http\Controllers;

use App\Classes\Encryption;
use App\Enums\PaymentType;
use App\Enums\TransactionType;
use App\Models\Client;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class UtilsController extends Controller
{
    public function testPayment(Request $request): RedirectResponse
    {
        $client_id = $request->query('clientId', 'LQPXDMJR6Q9NXNTN');

        $client = Client::where('client_id', $client_id)->first();

        if (! $client) {
            abort(404, 'Client not found');
        }

        $amount = $request->query('amount', 1000);
        $data = [
            'clientId' => $client_id,
            'currency' => 'INR',
            'amount' => $amount,
            'purpose' => 'General Donation',
            'paymentType' => $request->query('paymentType', PaymentType::ONE_TIME_PAYMENT->value),
            'transactionType' => $request->query('transactionType', TransactionType::SALE->value),
            'reference_id' => Str::random(10),
            'customer' => [
                'name' => 'Test Customer',
                'email' => 'dd@dd.com',
                'mobile' => '9303903901',
            ],
        ];

        return redirect()
            ->away(route('initPayment',
                ['clientId' => $client_id,
                    'data' => Encryption::encrypt($data, $client->client_secret)]));

    }
}
