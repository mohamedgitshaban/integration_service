<?php

namespace App\Services\Payments;

interface PaymentProvider
{
    /**
     * Move money to an instructor. The idempotency key identifies the transfer
     * for later status checks.
     *
     * @throws ProviderTimeoutException when no answer arrives; the money may or may not have moved
     */
    public function transfer(string $idempotencyKey, string $destination, int $amountMinor, string $currency): TransferResult;

    /**
     * Look up a transfer by the idempotency key it was sent with.
     *
     * @throws ProviderTimeoutException
     */
    public function status(string $idempotencyKey): TransferResult;
}
