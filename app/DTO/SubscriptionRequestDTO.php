<?php

namespace App\DTO;

use App\Enums\SubscriptionPeriod;
use App\Enums\SubscriptionType;
use Carbon\CarbonImmutable;
use Devhammed\LaravelBrickMoney\Currency;
use Devhammed\LaravelBrickMoney\Money;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapInputName(SnakeCaseMapper::class)]
class SubscriptionRequestDTO extends Data
{
    public readonly Currency $currency;

    public readonly Money $amount;

    public readonly Money $maxAmount;

    public readonly Money $authAmount;

    public readonly SubscriptionType $subscriptionType;

    public readonly ?SubscriptionPeriod $period;

    public readonly ?CarbonImmutable $expiresAt;

    /**
     * @var array<string, mixed>
     */
    public readonly array $customer;

    protected ?int $pgConnectionId;

    /**
     * @param  array<string, mixed>  $customer
     * @param  array<string, mixed>  $requestData
     */
    public function __construct(
        public readonly string $clientDbId,
        public readonly string $clientId,
        public readonly string $site_reference_id,
        string $currency,
        int|float|string $amount,
        int|float|string $maxAmount,
        SubscriptionType|string $subscriptionType,
        array $customer,
        int|float|string $authAmount = 0,
        SubscriptionPeriod|string|null $period = null,
        public readonly ?int $interval = 1,
        public readonly ?int $maxCycles = null,
        \DateTimeInterface|string|null $expiresAt = null,
        public readonly ?string $planName = null,
        public readonly array $requestData = [],
        ?int $pgConnectionId = null,
    ) {
        $this->currency = Currency::of($currency);
        $this->amount = Money::of($amount, $this->currency);
        $this->maxAmount = Money::of($maxAmount, $this->currency);
        $this->authAmount = Money::of($authAmount, $this->currency);

        $this->subscriptionType = $subscriptionType instanceof SubscriptionType
            ? $subscriptionType
            : SubscriptionType::from($subscriptionType);

        if ($period !== null) {
            $this->period = $period instanceof SubscriptionPeriod
                ? $period
                : SubscriptionPeriod::from($period);
        } else {
            $this->period = null;
        }

        if ($expiresAt !== null) {
            $this->expiresAt = $expiresAt instanceof CarbonImmutable
                ? $expiresAt
                : CarbonImmutable::parse($expiresAt);
        } else {
            $this->expiresAt = null;
        }

        $this->customer = $customer;
        $this->pgConnectionId = $pgConnectionId;
    }

    public function setPgConnectionId(int $pgConnectionId): void
    {
        $this->pgConnectionId = $pgConnectionId;
    }

    public function getPgConnectionId(): ?int
    {
        return $this->pgConnectionId;
    }
}
