<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\SubscriptionPeriod;
use App\Enums\SubscriptionType;
use Devhammed\LaravelBrickMoney\Casts\AsCurrency;
use Devhammed\LaravelBrickMoney\Casts\AsIntegerMoney;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Subscription extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'currency' => AsCurrency::class,
            'payment_method' => PaymentMethod::class,
            'status' => 'boolean',
            'amount' => AsIntegerMoney::of('currency'),
            'interval' => 'integer',
            'period' => SubscriptionPeriod::class,
            'start_date_time' => 'datetime',
            'end_date_time' => 'datetime',
            'subscription_type' => SubscriptionType::class,

        ];
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
