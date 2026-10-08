<?php

namespace App\Enums;

enum SubscriptionStatus: string
{
    case CREATED = 'CREATED';
    case INITIALIZED = 'INITIALIZED';
    case AUTHENTICATED = 'AUTHENTICATED';
    case BANK_APPROVAL_PENDING = 'BANK_APPROVAL_PENDING';
    case ACTIVE = 'ACTIVE';
    case PAUSED = 'PAUSED';
    case CANCELLED = 'CANCELLED';
    case COMPLETED = 'COMPLETED';
    case EXPIRED = 'EXPIRED';
    case FAILED = 'FAILED';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::CANCELLED, self::COMPLETED, self::EXPIRED, self::FAILED => true,
            default => false,
        };
    }

    public function canCharge(): bool
    {
        return $this === self::ACTIVE;
    }
}
