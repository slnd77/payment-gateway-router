<?php

namespace App\Enums;

enum SubscriptionPeriod: string
{
    case MONTHLY = 'monthly';
    case YEARLY = 'yearly';
    case WEEKLY = 'weekly';
    case DAILY = 'daily';
    case QUARTERLY = 'quarterly';
}
