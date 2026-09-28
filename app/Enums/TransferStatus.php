<?php

namespace App\Enums;

/**
 * What the payment provider says about a transfer. NotFound is only returned
 * by a status check and does not prove the transfer never happened: the
 * provider may not have made it visible yet.
 */
enum TransferStatus: string
{
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case NotFound = 'not_found';
}
