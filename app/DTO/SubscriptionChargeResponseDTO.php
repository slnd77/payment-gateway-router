<?php

namespace App\DTO;

use App\Enums\PaymentMethod;
use App\Enums\TransactionStatus;
use Carbon\CarbonImmutable;
use Devhammed\LaravelBrickMoney\Currency;
use Devhammed\LaravelBrickMoney\Money;
use Spatie\LaravelData\Data;

class SubscriptionChargeResponseDTO extends Data
{
    /**
     * @param  array<string, mixed>  $pgResponseRaw
     */
    public function __construct(
        public readonly string $chargeDbId,
        public readonly string $siteReferenceId,
        public readonly string $subscriptionReferenceId,
        public readonly TransactionStatus $status,
        public readonly string $transactionId,
        public readonly string $paymentId,
        public readonly Money $amount,
        public readonly Money $pgFees,
        public readonly Currency $currency,
        public readonly PaymentMethod $paymentMethod,
        public readonly CarbonImmutable $transactionDateTime,
        public readonly string $description,
        public readonly array $pgResponseRaw,
    ) {}
}
