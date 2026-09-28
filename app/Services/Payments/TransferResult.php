<?php

namespace App\Services\Payments;

use App\Enums\TransferStatus;

final readonly class TransferResult
{
    public function __construct(
        public TransferStatus $status,
        public ?string $reference = null,
        public ?string $message = null,
    ) {}
}
