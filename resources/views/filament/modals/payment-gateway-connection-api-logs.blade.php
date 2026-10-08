@livewire(\App\Livewire\PaymentGatewayConnectionApiLogsTable::class, [
    'pgConnection' => $pgConnection ?? null,
    'clientConnection' => $clientConnection ?? null,
    'transaction' => $transaction ?? null,
    'subscription' => $subscription ?? null,
])
