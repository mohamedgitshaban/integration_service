<?php

namespace App\Services;

use Exception;

class MockPaymentGateway
{
    /**
     * Transfer funds to an instructor's account.
     * 
     * Simulates 3 outcomes:
     * 1. Success ('success')
     * 2. Permanent Failure ('failed')
     * 3. Timeout / Unknown state ('timeout')
     */
    public function transfer(string $idempotencyKey, string $destinationAccount, float $amount): array
    {
        // For deterministic testing, we can check if a specific key is passed,
        // otherwise simulate random real-world unreliability.
        $outcome = random_int(1, 10);

        if ($outcome <= 6) {
            // 60% Success
            return [
                'status' => 'success',
                'provider_reference' => 'TXN-' . strtoupper(uniqid()),
                'message' => 'Transfer completed successfully.',
            ];
        } elseif ($outcome <= 8) {
            // 20% Permanent Failure
            return [
                'status' => 'failed',
                'provider_reference' => null,
                'message' => 'Insufficient gateway funds or invalid account.',
            ];
        } else {
            // 20% Timeout (Money might have moved or got stuck)
            throw new \RuntimeException('Gateway connection timed out. Status unknown.');
        }
    }

    /**
     * Check transaction status with the provider if a timeout occurred.
     */
    public function verifyStatus(string $providerReference): string
    {
        // Mock verification logic
        return 'success'; 
    }
}