<?php

namespace App\Services;

use App\Enums\LedgerEntryType;
use App\Models\InstructorBalance;
use App\Models\LedgerEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * The only writer of ledger_entries and instructor_balances. Each entry and
 * its balance change are written together inside the caller's transaction,
 * so the cached balance can never drift from the ledger.
 */
class LedgerService
{
    public function record(
        int $instructorId,
        LedgerEntryType $type,
        int $amountMinor,
        string $idempotencyKey,
        CarbonImmutable $occurredOn,
        ?int $revenueAllocationId = null,
        ?int $payoutId = null,
    ): LedgerEntry {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Ledger writes must run inside a database transaction.');
        }

        $this->assertSign($type, $amountMinor);

        $balance = $this->lockBalance($instructorId);

        $entry = LedgerEntry::create([
            'instructor_id' => $instructorId,
            'type' => $type,
            'amount_minor' => $amountMinor,
            'currency' => config('ledger.currency'),
            'revenue_allocation_id' => $revenueAllocationId,
            'payout_id' => $payoutId,
            'idempotency_key' => $idempotencyKey,
            'occurred_on' => $occurredOn,
        ]);

        match ($type) {
            LedgerEntryType::Earning, LedgerEntryType::Clawback => $balance->earned_minor += $amountMinor,
            LedgerEntryType::Payout, LedgerEntryType::PayoutReversal => $balance->reserved_minor -= $amountMinor,
        };

        $balance->save();

        return $entry;
    }

    /**
     * Move a confirmed payout from reserved to paid. The ledger already holds
     * the payout debit (written at reservation), so only the projection's
     * split between in-flight and confirmed money changes.
     */
    public function markReservedAsPaid(int $instructorId, int $amountMinor): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Ledger writes must run inside a database transaction.');
        }

        $balance = $this->lockBalance($instructorId);
        $balance->reserved_minor -= $amountMinor;
        $balance->paid_minor += $amountMinor;
        $balance->save();
    }

    /**
     * Lock (creating if needed) the instructor's balance row. Callers touching
     * several instructors must lock them in ascending id order to avoid
     * deadlocks between concurrent transactions.
     */
    public function lockBalance(int $instructorId): InstructorBalance
    {
        InstructorBalance::query()->insertOrIgnore([
            'instructor_id' => $instructorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return InstructorBalance::query()
            ->where('instructor_id', $instructorId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function assertSign(LedgerEntryType $type, int $amountMinor): void
    {
        $mustBePositive = match ($type) {
            LedgerEntryType::Earning, LedgerEntryType::PayoutReversal => true,
            LedgerEntryType::Clawback, LedgerEntryType::Payout => false,
        };

        if ($amountMinor === 0 || ($amountMinor > 0) !== $mustBePositive) {
            throw new InvalidArgumentException("Invalid amount {$amountMinor} for a {$type->value} entry.");
        }
    }
}
