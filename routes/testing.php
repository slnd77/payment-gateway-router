<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Testing-only routes
|--------------------------------------------------------------------------
|
| Only loaded when APP_ENV=testing (see routes/web.php). These stand in for
| pieces of the payment flow that live outside this app - the client's own
| redirect landing page, and (for ICICI only, since PGSimulator already
| hosts its own real checkout page) the bank's hosted checkout page - so
| browser tests can drive the whole round trip without reaching the
| internet.
|
*/

Route::get('test/return', function (Request $request) {
    return response()->json(['received' => true] + $request->query());
})->name('test.return');

Route::get('test/icici-hosted-checkout', function (Request $request) {
    $hidden = collect($request->only(['addlParam1', 'addlParam2', 'merchantTxnNo']))
        ->merge([
            'amount' => '1000.00',
            'oth_charge' => '10.00',
            'txnID' => 'MOCKTXN'.$request->query('addlParam1'),
            'paymentMode' => 'CARD',
            'paymentDateTime' => now()->format('YmdHis'),
            'respDescription' => 'Simulated by browser test',
        ])
        ->map(fn ($value, $key) => sprintf('<input type="hidden" name="%s" value="%s">', e((string) $key), e((string) $value)))
        ->implode('');

    $responseUrl = route('handlePaymentResponse', ['pgClass' => 'ICICI']);

    return response(<<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <body>
            <h1>ICICI Hosted Checkout (test stub)</h1>
            <form method="POST" action="{$responseUrl}">
                {$hidden}
                <button type="submit" name="responseCode" value="0000">Simulate Success</button>
                <button type="submit" name="responseCode" value="E0001">Simulate Failure</button>
            </form>
        </body>
        </html>
        HTML);
})->name('test.iciciHostedCheckout');
