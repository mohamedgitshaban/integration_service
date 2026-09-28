<?php

namespace App\Services;

use App\Models\InstructorBalance;
use App\Models\InstructorEarning;
use App\Models\PayoutBatch;
use App\Models\PayoutTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;


class PayoutService
{
    protected MockPaymentGateway $gateway;

    public function __construct(MockPaymentGateway $gateway)
    {
        $this->gateway = $gateway;
    }

    public function processBatch(PayoutBatch $batch): void
    {
        // Fetch earnings that are ready for payout
        $earnings = InstructorEarning::where('status', 'pending')->with('instructor')->get();

        foreach ($earnings as $earning) {
            $instructor = $earning->instructor;
            if (!$instructor || !$instructor->bank_account_number) {
                continue;
            }

            // Generate deterministic idempotency key to prevent double payouts on retries
            $idempotencyKey = 'payout_' . $earning->id . '_' . $instructor->id;

            DB::transaction(function () use ($batch, $earning, $instructor, $idempotencyKey) {
                // Check if a transaction with this idempotency key already succeeded or is pending
                $existingTx = PayoutTransaction::where('idempotency_key', $idempotencyKey)->first();

                if ($existingTx && $existingTx->status === 'success') {
                    return; // Already paid! Skip entirely.
                }

                // Create or retrieve transaction record
                $transaction = PayoutTransaction::firstOrCreate(
                    ['idempotency_key' => $idempotencyKey],
                    [
                        'payout_batch_id' => $batch->id,
                        'instructor_id' => $instructor->id,
                        'instructor_earning_id' => $earning->id,
                        'amount' => $earning->net_amount,
                        'currency' => 'EGP',
                        'status' => 'pending',
                    ]
                );

                if ($transaction->status === 'success') {
                    return;
                }

                try {
                    // Call mock gateway
                    $response = $this->gateway->transfer(
                        $idempotencyKey,
                        $instructor->bank_account_number,
                        (float) $earning->net_amount
                    );

                    if ($response['status'] === 'success') {
                        $transaction->update([
                            'status' => 'success',
                            'provider_reference' => $response['provider_reference'],
                        ]);

                        $earning->update(['status' => 'paid']);

                        // Update instructor balances safely
                        $balance = InstructorBalance::where('instructor_id', $instructor->id)->lockForUpdate()->first();
                        if ($balance) {
                            $balance->decrement('pending_balance', $earning->net_amount);
                            $balance->increment('paid_out_balance', $earning->net_amount);
                        }
                    } else {
                        $transaction->update([
                            'status' => 'failed',
                            'error_message' => $response['message'],
                        ]);
                    }
                } catch (\Throwable $e) {
                    // Handle timeout / connection failure safely
                    $transaction->update([
                        'status' => 'timeout',
                        'error_message' => $e->getMessage(),
                    ]);
                    
                    // Re-throw if job needs retry, but our idempotency key ensures no double pay!
                    throw $e;
                }
            });
        }

        $batch->update([
            'status' => 'completed',
            'processed_at' => now(),
        ]);
    }
}