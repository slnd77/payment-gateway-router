<?php

namespace App\Events;

use App\Enums\ClientApiLogResult;
use Illuminate\Foundation\Events\Dispatchable;

class ClientApiEvent
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    public function __construct(
        public readonly ?int $clientId,
        public readonly string $event,
        public readonly ClientApiLogResult $result,
        public readonly array $requestData = [],
        public readonly array $responseData = [],
        public readonly ?string $sourceIp = null,
    ) {}
}
