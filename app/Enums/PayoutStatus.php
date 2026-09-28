<?php

namespace App\Enums;

/**
 * Pending: reserved, not yet sent. Processing: claimed by a worker, provider
 * call in flight. Unknown: provider did not answer; money may have moved, so
 * it must be resolved by a status check and never re-sent blindly.
 */
enum PayoutStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Unknown = 'unknown';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function isFinal(): bool
    {
        return $this === self::Succeeded || $this === self::Failed;
    }
}
