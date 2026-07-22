<?php

namespace App\Enums;

enum PaymentType: string
{
    case ONE_TIME_PAYMENT = 'one_time_payment';
    case SUBSCRIPTION = 'subscription';
}
