<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\TransactionStatus;
use Carbon\CarbonImmutable;
use Devhammed\LaravelBrickMoney\Casts\AsCurrency;
use Devhammed\LaravelBrickMoney\Casts\AsIntegerMoney;
use Devhammed\LaravelBrickMoney\Currency;
use Devhammed\LaravelBrickMoney\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property array{amount: Money, currency: Currency} $amount
 * @property array{transaction_amount: Money, currency: Currency} $transaction_amount
 * @property array{service_tax_amount: Money, currency: Currency} $service_tax_amount
 * @property array{processing_fee_amount: Money, currency: Currency} $processing_fee_amount
 * @property array{pg_fees: Money, currency: Currency} $pg_fees
 * @property array{total_amount: Money, currency: Currency} $total_amount
 * @property array<string, mixed>|null $request_data
 * @property array<string, mixed>|null $response_data
 * @property TransactionStatus $status
 * @property PaymentMethod|null $payment_method
 * @property Currency $currency
 * @property CarbonImmutable|null $transaction_date_time
 */
class Transaction extends Model
{
    protected function casts(): array
    {
        return [
            'amount' => AsIntegerMoney::of('currency'),
            'transaction_amount' => AsIntegerMoney::of('currency'),
            'currency' => AsCurrency::class,
            'status' => TransactionStatus::class,
            'payment_method' => PaymentMethod::class,
            'service_tax_amount' => AsIntegerMoney::of('currency'),
            'processing_fee_amount' => AsIntegerMoney::of('currency'),
            'recover_pg_fees' => 'boolean',
            'pg_fees' => AsIntegerMoney::of('currency'),
            'total_amount' => AsIntegerMoney::of('currency'),
            'transaction_date_time' => 'datetime',
            'request_data' => 'array',
            'response_data' => 'array',
        ];
    }

    /**
     * @return BelongsTo<ClientCustomer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(ClientCustomer::class);
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<PGConnection, $this>
     */
    public function pgConnection(): BelongsTo
    {
        return $this->belongsTo(PGConnection::class, 'pg_connection_id');
    }

    /**
     * @return HasMany<PaymentGatewayConnectionApiLog, $this>
     */
    public function apiLogs(): HasMany
    {
        return $this->hasMany(PaymentGatewayConnectionApiLog::class);
    }
}
