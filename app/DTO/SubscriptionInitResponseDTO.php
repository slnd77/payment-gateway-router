<?php

namespace App\DTO;

use Spatie\LaravelData\Data;

class SubscriptionInitResponseDTO extends Data
{
    public function __construct(
        public readonly string $url,
        public readonly string $subscriptionId,
        public readonly bool $selfRedirect = true,
        public readonly ?string $session = null,
    ) {}
}
