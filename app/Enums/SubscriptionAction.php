<?php

namespace App\Enums;

enum SubscriptionAction: string
{
    case CANCEL = 'CANCEL';
    case PAUSE = 'PAUSE';
    case RESUME = 'RESUME';
}
