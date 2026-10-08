<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\SubscriptionPeriod;
use App\Enums\SubscriptionStatus;
use App\Enums\SubscriptionType;
use Carbon\CarbonImmutable;
use Devhammed\LaravelBrickMoney\Casts\AsCurrency;
use Devhammed\LaravelBrickMoney\Casts\AsIntegerMoney;
use Devhammed\LaravelBrickMoney\Currency;
use Devhammed\LaravelBrickMoney\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $client_id
 * @property int $client_customer_id
 * @property int $pg_connection_id
 * @property SubscriptionType $subscription_type
 * @property string $site_reference_id
 * @property string|null $subscription_id
 * @property string|null $pg_reference_id
 * @property string|null $authorization_reference
 * @property string|null $plan_id
 * @property string|null $plan_name
 * @property CarbonImmutable|null $start_date_time
 * @property CarbonImmutable|null $end_date_time
 * @property CarbonImmutable|null $next_charge_date_time
 * @property SubscriptionPeriod|null $period
 * @property int|null $interval
 * @property int|null $max_cycles
 * @property-read array{amount: Money, currency: Currency} $amount
 * @property-write Money $amount
 * @property-read array{max_amount: Money, currency: Currency} $max_amount
 * @property-write Money $max_amount
 * @property-read array{auth_amount: Money, currency: Currency} $auth_amount
 * @property-write Money $auth_amount
 * @property PaymentMethod|null $payment_method
 * @property Currency $currency
 * @property SubscriptionStatus $status
 * @property array<string, mixed>|null $request_data
 * @property array<string, mixed>|null $response_data
 */
class Subscription extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'currency' => AsCurrency::class,
            'payment_method' => PaymentMethod::class,
            'status' => SubscriptionStatus::class,
            'subscription_type' => SubscriptionType::class,
            'amount' => AsIntegerMoney::of('currency'),
            'max_amount' => AsIntegerMoney::of('currency'),
            'auth_amount' => AsIntegerMoney::of('currency'),
            'interval' => 'integer',
            'max_cycles' => 'integer',
            'period' => SubscriptionPeriod::class,
            'start_date_time' => 'datetime',
            'end_date_time' => 'datetime',
            'next_charge_date_time' => 'datetime',
            'request_data' => 'array',
            'response_data' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<ClientCustomer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(ClientCustomer::class, 'client_customer_id');
    }

    /**
     * @return BelongsTo<PGConnection, $this>
     */
    public function pgConnection(): BelongsTo
    {
        return $this->belongsTo(PGConnection::class, 'pg_connection_id');
    }

    /**
     * @return HasMany<SubscriptionTransaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(SubscriptionTransaction::class, 'subscription_id');
    }

    /**
     * @return HasMany<PaymentGatewayConnectionApiLog, $this>
     */
    public function apiLogs(): HasMany
    {
        return $this->hasMany(PaymentGatewayConnectionApiLog::class, 'subscription_id');
    }
}
