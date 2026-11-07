<?php

namespace App\Http\Controllers;

use App\DTO\PaymentRequestDTO;
use App\Enums\PaymentType;
use App\Enums\TransactionType;
use App\Models\Client;
use App\Services\TransactionService;
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
        $referenceId = Str::random(10);
        $data = [
            'clientDbId' => (string) $client->id,
            'clientId' => $client_id,
            'currency' => 'INR',
            'amount' => $amount,
            'purpose' => 'General Donation',
            'paymentType' => $request->query('paymentType', PaymentType::ONE_TIME_PAYMENT->value),
            'transactionType' => $request->query('transactionType', TransactionType::SALE->value),
            'reference_id' => $referenceId,
            'site_reference_id' => $referenceId,
            'customer' => [
                'name' => 'Test Customer',
                'email' => 'dd@dd.com',
                'mobile' => '9303903901',
            ],
        ];

        $paymentRequestDto = PaymentRequestDTO::from($data);
        $result = app(TransactionService::class)->initiatePayment($paymentRequestDto);

        return redirect()->away($result['url']);
    }
}
