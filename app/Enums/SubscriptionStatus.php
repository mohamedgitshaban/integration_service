<?php

namespace App\Enums;

enum SubscriptionStatus: string
{
    case Active = 'active';
    case Refunded = 'refunded';
}
