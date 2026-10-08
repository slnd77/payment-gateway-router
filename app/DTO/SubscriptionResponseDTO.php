<?php

namespace App\DTO;

use App\Enums\PaymentMethod;
use App\Enums\SubscriptionPeriod;
use App\Enums\SubscriptionStatus;
use App\Enums\SubscriptionType;
use Carbon\CarbonImmutable;
use Devhammed\LaravelBrickMoney\Currency;
use Devhammed\LaravelBrickMoney\Money;
use Spatie\LaravelData\Data;

class SubscriptionResponseDTO extends Data
{
    /**
     * @param  array<string, mixed>  $pgResponseRaw
     */
    public function __construct(
        public readonly string $subscriptionDbId,
        public readonly string $siteReferenceId,
        public readonly SubscriptionStatus $status,
        public readonly string $subscriptionId,
        public readonly ?string $pgReferenceId,
        public readonly ?string $authorizationReference,
        public readonly SubscriptionType $subscriptionType,
        public readonly Money $amount,
        public readonly Money $maxAmount,
        public readonly Currency $currency,
        public readonly ?SubscriptionPeriod $period,
        public readonly ?int $interval,
        public readonly PaymentMethod $paymentMethod,
        public readonly ?CarbonImmutable $nextChargeDateTime,
        public readonly string $description,
        public readonly string $clientName,
        public readonly string $pgConnection,
        public readonly array $pgResponseRaw,
        public readonly string $type = 'subscription',
    ) {}
}
