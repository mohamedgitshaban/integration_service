<?php

namespace App\Enums;

/**
 * ProRata: days before the effective date stay earned; only the unserved
 * remainder is refunded. Full: the whole payment is returned and every
 * instructor earning from the subscription is clawed back (cooling-off,
 * fraud, service failure).
 */
enum RefundPolicy: string
{
    case ProRata = 'pro_rata';
    case Full = 'full';
}
