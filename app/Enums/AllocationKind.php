<?php

namespace App\Enums;

/**
 * Recognition earns served days; Reversal un-earns days that were recognised
 * but are no longer served (backdated or full refund). Amounts are stored
 * positive on both; the kind gives the direction.
 */
enum AllocationKind: string
{
    case Recognition = 'recognition';
    case Reversal = 'reversal';
}
