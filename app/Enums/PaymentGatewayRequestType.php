<?php

namespace App\Enums;

enum PaymentGatewayRequestType: string
{
    case PAYMENT_INITIATE = 'payment_initiate';
    case STATUS_CHECK = 'status_check';
    case REFUND = 'refund';
    case SETTLEMENT_DETAILS = 'settlement_details';
}
