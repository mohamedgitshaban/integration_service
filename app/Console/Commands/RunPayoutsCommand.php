<?php

namespace App\Console\Commands;

use App\Models\PayoutBatch;
use App\Services\PayoutService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class RunPayoutsCommand extends Command
{
    protected $signature = 'payouts:run';
    protected $description = 'Run scheduled instructor payouts safely and idempotently.';

    public function handle(PayoutService $payoutService): int
    {
        $this->info('Starting payout batch execution...');

        $batch = PayoutBatch::create([
            'reference_number' => 'BATCH-' . strtoupper(Str::random(10)),
            'total_amount' => 0.00, // Can aggregate total if needed
            'status' => 'processing',
        ]);

        try {
            $payoutService->processBatch($batch);
            $this->info('Payout batch completed successfully.');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Payout batch encountered an error: ' . $e->getMessage());
            $batch->update(['status' => 'failed']);
            return self::FAILURE;
        }
    }
}