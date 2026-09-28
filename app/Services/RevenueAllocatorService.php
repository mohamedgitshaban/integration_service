<?php

namespace App\Services;

use App\Models\Subscription;
use App\Models\InstructorEarning;
use App\Models\InstructorBalance;
use Illuminate\Support\Facades\DB;
use Exception;

class RevenueAllocatorService
{
    protected float $platformCommissionRate;

    public function __construct(float $platformCommissionRate = 0.30)
    {
        // Default platform cut is 30%, instructors share 70%
        $this->platformCommissionRate = $platformCommissionRate;
    }

    /**
     * Allocate subscription payment revenue to respective instructors.
     */
    public function allocate(Subscription $subscription): void
    {
        DB::transaction(function () use ($subscription) {
            $courses = $subscription->courses()->with('instructor')->get();

            if ($courses->isEmpty()) {
                return;
            }

            $totalAmount = (float) $subscription->amount_paid;
            $platformFeeTotal = round($totalAmount * 0.30, 2);
            $netPool = $totalAmount - $platformFeeTotal;

            // Group unique instructors involved in this subscription
            $instructors = $courses->pluck('instructor')->unique('id');
            $instructorCount = $instructors->count();

            if ($instructorCount === 0) {
                return;
            }

            // Split net pool evenly among instructors
            $sharePerInstructor = floor(($netPool / $instructorCount) * 100) / 100;
            $allocatedSoFar = 0.0;

            foreach ($instructors as $index => $instructor) {
                // Handle rounding remainder on the last instructor
                $instructorNet = ($index === $instructorCount - 1)
                    ? round($netPool - $allocatedSoFar, 2)
                    : $sharePerInstructor;

                $allocatedSoFar += $instructorNet;

                // Gross attributed proportionally for reporting
                $instructorGross = round($instructorNet / (1 - 0.30), 2);
                $instructorPlatformFee = round($instructorGross * 0.30, 2);

                // Create ledger entry
                $earning = InstructorEarning::create([
                    'instructor_id' => $instructor->id,
                    'subscription_id' => $subscription->id,
                    'gross_amount' => $instructorGross,
                    'platform_fee' => $instructorPlatformFee,
                    'net_amount' => $instructorNet,
                    'status' => 'pending', // or 'available' depending on business rules
                ]);

                // Update or create instructor balance
                $balance = InstructorBalance::firstOrCreate(
                    ['instructor_id' => $instructor->id],
                    ['pending_balance' => 0, 'available_balance' => 0, 'paid_out_balance' => 0]
                );

                $balance->increment('pending_balance', $instructorNet);
            }
        });
    }
}