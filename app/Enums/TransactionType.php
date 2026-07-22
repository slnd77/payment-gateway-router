<?php

namespace App\Enums;

enum TransactionType: string
{
    case SALE = 'sale';
    case DONATION = 'donation';

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::SALE->value => 'Sale',
            self::DONATION->value => 'Donation',
        ];
    }
}
