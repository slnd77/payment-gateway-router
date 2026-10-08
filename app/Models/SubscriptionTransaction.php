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

/**
 * @property int $id
 * @property int $subscription_id
 * @property int|null $client_id
 * @property string|null $site_reference_id
 * @property string|null $payment_id
 * @property string|null $transaction_id
 * @property-read array{amount: Money, currency: Currency} $amount
 * @property-write Money $amount
 * @property Currency $currency
 * @property TransactionStatus $status
 * @property PaymentMethod|null $payment_method
 * @property-read array{pg_fees: Money, currency: Currency} $pg_fees
 * @property-write Money $pg_fees
 * @property-read array{pg_tax: Money, currency: Currency} $pg_tax
 * @property-write Money $pg_tax
 * @property string|null $remarks
 * @property array<string, mixed>|null $data
 * @property CarbonImmutable|null $transaction_date_time
 */
class SubscriptionTransaction extends Model
{
    protected $table = 'subscriptions_transactions';

    protected function casts(): array
    {
        return [
            'amount' => AsIntegerMoney::of('currency'),
            'currency' => AsCurrency::class,
            'status' => TransactionStatus::class,
            'payment_method' => PaymentMethod::class,
            'pg_fees' => AsIntegerMoney::of('currency'),
            'pg_tax' => AsIntegerMoney::of('currency'),
            'transaction_date_time' => 'datetime',
            'data' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
