<?php

namespace App\Enums;

/**
 * Signed movements on an instructor's ledger. Earnings and payout reversals
 * are positive; clawbacks and payouts are negative.
 */
enum LedgerEntryType: string
{
    case Earning = 'earning';
    case Clawback = 'clawback';
    case Payout = 'payout';
    case PayoutReversal = 'payout_reversal';
}
