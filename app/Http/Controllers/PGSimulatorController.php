<?php

namespace App\Http\Controllers;

use App\Models\Subscription;
use App\Models\Transaction;
use Brick\Math\RoundingMode;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;

class PGSimulatorController extends Controller
{
    public function checkout(Transaction $transaction): View
    {
        $transaction->loadMissing(['client', 'pgConnection']);

        $feesRate = (float) ($transaction->pgConnection->attributes['fees_rate'] ?? 0);
        $amount = $transaction->amount['amount'];
        $pgFees = $amount->multipliedBy($feesRate / 100, RoundingMode::HALF_UP);

        return view('pg-simulator.checkout', [
            'transaction' => $transaction,
            'amount' => $amount,
            'pgFees' => $pgFees,
            'transactionId' => 'SIM'.strtoupper(Str::random(12)),
            'responseUrl' => route('handlePaymentResponse', ['pgClass' => 'PGSimulator']),
        ]);
    }

    public function subscriptionCheckout(Subscription $subscription): View
    {
        $subscription->loadMissing(['client', 'pgConnection']);

        return view('pg-simulator.subscription-checkout', [
            'subscription' => $subscription,
            'responseUrl' => route('handleSubscriptionResponse', ['pgClass' => 'PGSimulator']),
        ]);
    }
}
