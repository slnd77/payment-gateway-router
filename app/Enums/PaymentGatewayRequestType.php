<?php

namespace App\Enums;

enum PaymentGatewayRequestType: string
{
    case PAYMENT_INITIATE = 'payment_initiate';
    case STATUS_CHECK = 'status_check';
    case REFUND = 'refund';
    case SETTLEMENT_DETAILS = 'settlement_details';
    case SUBSCRIPTION_CREATE = 'subscription_create';
    case SUBSCRIPTION_STATUS = 'subscription_status';
    case SUBSCRIPTION_MANAGE = 'subscription_manage';
    case SUBSCRIPTION_CHARGE = 'subscription_charge';
    case SUBSCRIPTION_CHARGE_STATUS = 'subscription_charge_status';
    case SUBSCRIPTION_CHARGES_LIST = 'subscription_charges_list';
}
