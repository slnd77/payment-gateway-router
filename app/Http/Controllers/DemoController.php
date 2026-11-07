<?php

namespace App\Http\Controllers;

use App\Classes\Encryption;
use App\Enums\PaymentType;
use App\Enums\TransactionType;
use App\Models\Client;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * A sample merchant that integrates with the router the same way a real
 * client would: it encrypts a payload with its client_secret, calls
 * /api/v1/initPayment over HTTP, sends the customer to the returned URL, and
 * decrypts the result on its redirect_uri.
 */
class DemoController extends Controller
{
    public function index(): View
    {
        $this->ensureDemoEnabled();

        return view('demo.index', [
            'gateways' => $this->availableGateways(),
            'admin' => config('demo.admin'),
            'resetCron' => config('demo.reset_cron'),
        ]);
    }

    public function pay(Request $request): RedirectResponse
    {
        $this->ensureDemoEnabled();

        $gateways = $this->availableGateways();

        $validated = $request->validate([
            'gateway' => ['required', 'string', 'in:'.implode(',', array_keys($gateways))],
            'amount' => ['required', 'numeric', 'min:1', 'max:100000'],
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email'],
            'mobile' => ['required', 'digits:10'],
        ]);

        $client = $this->clientFor($validated['gateway']);
        $referenceId = 'demo-'.Str::lower(Str::random(10));

        $payload = [
            'reference_id' => $referenceId,
            'clientId' => $client->client_id,
            'currency' => $gateways[$validated['gateway']]['currency'],
            'amount' => (float) $validated['amount'],
            'transactionType' => TransactionType::SALE->value,
            'paymentType' => PaymentType::ONE_TIME_PAYMENT->value,
            'purpose' => 'Demo order',
            'customer' => [
                'name' => $validated['name'],
                'email' => $validated['email'],
                'mobile' => $validated['mobile'],
            ],
        ];

        $response = Http::timeout(30)->get(route('initPayment'), [
            'clientId' => $client->client_id,
            'data' => Encryption::encrypt($payload, $client->client_secret),
        ]);

        if (! $response->successful() || ! is_string($response->json('url'))) {
            return back()->withInput()->withErrors([
                'gateway' => 'The router rejected the request: '.Str::limit($response->body(), 300),
            ]);
        }

        return redirect()->away($response->json('url'));
    }

    public function return(Request $request, string $gateway): View
    {
        $this->ensureDemoEnabled();

        $client = $this->clientFor($gateway);
        $result = null;
        $error = null;

        try {
            $result = json_decode(Encryption::decrypt((string) $request->query('data'), $client->client_secret), true);
        } catch (\Throwable) {
            $error = 'Could not decrypt the response from the router.';
        }

        return view('demo.return', [
            'gateway' => config("demo.gateways.$gateway.label", $gateway),
            'result' => is_array($result) ? $result : null,
            'rows' => is_array($result) ? array_map($this->formatValue(...), $result) : [],
            'error' => $error ?? (is_array($result) ? null : 'Empty response from the router.'),
        ]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function availableGateways(): array
    {
        return array_filter(
            (array) config('demo.gateways'),
            fn (array $gateway, string $key): bool => $gateway['attributes'] !== []
                && Client::where('client_id', 'DEMO'.Str::upper($key))->exists(),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * Money values arrive as {amount, currency: {code, ...}}; show them as "INR 499.00".
     */
    private function formatValue(mixed $value): string
    {
        return match (true) {
            is_array($value) && isset($value['amount'], $value['currency']['code']) => $value['currency']['code'].' '.$value['amount'],
            is_array($value) && isset($value['code']) && is_string($value['code']) => $value['code'],
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => '-',
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value, JSON_UNESCAPED_SLASHES),
        };
    }

    private function ensureDemoEnabled(): void
    {
        abort_unless(config('demo.enabled'), 404);
    }

    private function clientFor(string $gateway): Client
    {
        return Client::where('client_id', 'DEMO'.Str::upper($gateway))->firstOrFail();
    }
}
