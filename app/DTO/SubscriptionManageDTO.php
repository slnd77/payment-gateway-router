<?php

namespace App\DTO;

use App\Enums\SubscriptionAction;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

class SubscriptionManageDTO extends Data
{
    public readonly SubscriptionAction $action;

    public readonly ?CarbonImmutable $nextScheduledTime;

    public function __construct(
        SubscriptionAction|string $action,
        \DateTimeInterface|string|null $nextScheduledTime = null,
    ) {
        $this->action = $action instanceof SubscriptionAction
            ? $action
            : SubscriptionAction::from($action);

        if ($nextScheduledTime !== null) {
            $this->nextScheduledTime = $nextScheduledTime instanceof CarbonImmutable
                ? $nextScheduledTime
                : CarbonImmutable::parse($nextScheduledTime);
        } else {
            $this->nextScheduledTime = null;
        }
    }
}
