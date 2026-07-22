<?php

namespace App\Enums;

enum ConnectionType: string
{
    case TEST = 'TEST';
    case PRODUCTION = 'PRODUCTION';

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            'TEST' => self::TEST->value,
            'PRODUCTION' => self::PRODUCTION->value,
        ];
    }
}
