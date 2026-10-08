<?php

namespace App\DTO;

use Devhammed\LaravelBrickMoney\Currency;
use Devhammed\LaravelBrickMoney\Money;
use Spatie\LaravelData\Data;

class SubscriptionChargeRequestDTO extends Data
{
    public readonly Currency $currency;

    public readonly Money $amount;

    public function __construct(
        public readonly string $site_reference_id,
        public readonly string $charge_reference_id,
        int|float|string $amount,
        string $currency,
        public readonly ?string $remarks = null,
    ) {
        $this->currency = Currency::of($currency);
        $this->amount = Money::of($amount, $this->currency);
    }
}
