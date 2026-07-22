<?php

namespace App\DTO;

use Devhammed\LaravelBrickMoney\Currency;
use Devhammed\LaravelBrickMoney\Money;
use Spatie\LaravelData\Data;

class PaymentRefundDTO extends Data
{
    public readonly Currency $currency;

    public readonly Money $amount;

    protected ?int $pgConnectionId;

    /**
     * @param  array<string, mixed>  $requestData
     */
    public function __construct(
        public readonly string $clientDbId,
        public readonly string $clientId,
        string $currency,
        int|float|string $amount,
        public readonly string $site_reference_id,
        public readonly string $refundReason,
        public readonly array $requestData = [],
        ?int $pgConnectionId = null
    ) {
        $this->currency = Currency::of($currency);
        $this->amount = Money::of($amount, $this->currency);

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
