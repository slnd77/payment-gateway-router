<?php

namespace App\Enums;

enum SubscriptionType: string
{
    case PERIODIC = 'periodic';
    case ON_DEMAND = 'on_demand';
}
