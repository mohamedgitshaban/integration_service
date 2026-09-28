<?php

namespace App\Services;

use App\Enums\LedgerEntryType;
use App\Enums\PayoutStatus;
use App\Enums\TransferStatus;
use App\Jobs\SendPayoutJob;
use App\Models\InstructorBalance;
use App\Models\Payout;
use App\Models\PayoutRun;
use App\Models\User;
use App\Services\Payments\PaymentProvider;
use App\Services\Payments\TransferResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Pays instructors what they are owed, exactly once.
 *
 * 1. Reserve: under the instructor's balance lock, the whole outstanding
 *    amount becomes a pending payout plus a ledger debit. A second or
 *    overlapping run sees nothing outstanding.
 * 2. Claim: an atomic pending -> processing update; only one worker wins,
 *    so duplicate or retried jobs never reach the provider.
 * 3. Send: the provider is called outside any transaction, with the key
 *    fixed at reservation.
 * 4. Settle: success confirms the payment; a definite failure reverses the
 *    reservation. Anything ambiguous (timeout, crash, unexpected error)
 *    becomes "unknown" and is only ever resolved by a status check, never
 *    by sending again.
 */
class PayoutService
{
    public function __construct(
        private LedgerService $ledger,
        private PaymentProvider $provider,
    ) {}

    /**
     * Reserve a payout for every instructor with enough outstanding and a
     * payout destination on file.
     *
     * @param  callable(Payout): void  $onReserved  called after each reservation commits
     */
    public function reservePayouts(PayoutRun $run, callable $onReserved): void
    {
        $minimumMinor = max(1, (int) config('ledger.minimum_payout_minor'));

        InstructorBalance::query()
            ->whereRaw('earned_minor - reserved_minor - paid_minor >= ?', [$minimumMinor])
            ->whereHas('instructor', fn ($query) => $query->whereNotNull('bank_account_number'))
            ->select(['id', 'instructor_id'])
            ->chunkById(500, function ($balances) use ($run, $minimumMinor, $onReserved) {
                foreach ($balances as $balance) {
                    if ($payout = $this->reserveFor($balance->instructor_id, $run, $minimumMinor)) {
                        $onReserved($payout);
                    }
                }
            });
    }

    public function reserveFor(int $instructorId, PayoutRun $run, int $minimumMinor): ?Payout
    {
        return DB::transaction(function () use ($instructorId, $run, $minimumMinor) {
            $amountMinor = $this->ledger->lockBalance($instructorId)->outstandingMinor();
            $destination = User::whereKey($instructorId)->value('bank_account_number');

            if ($amountMinor < $minimumMinor || blank($destination)) {
                return null;
            }

            return $this->createReservation($instructorId, $amountMinor, $destination, $run);
        });
    }

    /**
     * An instructor asks to be paid their whole outstanding balance now.
     *
     * Goes through the same balance lock as scheduled runs, so a withdrawal
     * and a run can never both reserve the same money. With a request key,
     * repeating the request (double tap, client retry) returns the payout
     * created the first time instead of an error or a second payout.
     *
     * @return array{Payout, bool} the payout, and whether it was created by this call
     *
     * @throws ValidationException when there is nothing to withdraw or no payout destination
     */
    public function requestWithdrawal(User $instructor, ?string $requestKey = null): array
    {
        [$payout, $created] = DB::transaction(function () use ($instructor, $requestKey) {
            $balance = $this->ledger->lockBalance($instructor->id);

            if ($requestKey !== null) {
                $existing = Payout::query()
                    ->where('instructor_id', $instructor->id)
                    ->where('withdrawal_request_key', $requestKey)
                    ->first();

                if ($existing) {
                    return [$existing, false];
                }
            }

            $destination = User::whereKey($instructor->id)->value('bank_account_number');

            if (blank($destination)) {
                throw ValidationException::withMessages(['bank_account_number' => 'Add payout details before withdrawing.']);
            }

            $minimumMinor = max(1, (int) config('ledger.minimum_payout_minor'));
            $amountMinor = $balance->outstandingMinor();

            if ($amountMinor < $minimumMinor) {
                throw ValidationException::withMessages([
                    'amount' => "Outstanding balance {$amountMinor} is below the minimum withdrawal of {$minimumMinor}.",
                ]);
            }

            return [$this->createReservation($instructor->id, $amountMinor, $destination, requestKey: $requestKey), true];
        });

        if ($created) {
            SendPayoutJob::dispatch($payout->id);
        }

        return [$payout, $created];
    }

    /**
     * Must run inside the transaction holding the instructor's balance lock.
     */
    private function createReservation(int $instructorId, int $amountMinor, string $destination, ?PayoutRun $run = null, ?string $requestKey = null): Payout
    {
        $payout = Payout::create([
            'payout_run_id' => $run?->id,
            'instructor_id' => $instructorId,
            'amount_minor' => $amountMinor,
            'currency' => config('ledger.currency'),
            'destination_account' => $destination,
            'status' => PayoutStatus::Pending,
            'idempotency_key' => 'payout-'.Str::uuid(),
            'withdrawal_request_key' => $requestKey,
        ]);

        $this->ledger->record(
            instructorId: $instructorId,
            type: LedgerEntryType::Payout,
            amountMinor: -$amountMinor,
            idempotencyKey: "payout:{$payout->id}",
            occurredOn: CarbonImmutable::today(),
            payoutId: $payout->id,
        );

        return $payout;
    }

    /**
     * Send a pending payout to the provider. Safe to call any number of times,
     * concurrently: only the caller that claims the row talks to the provider.
     */
    public function send(int $payoutId): void
    {
        $claimed = Payout::query()
            ->whereKey($payoutId)
            ->where('status', PayoutStatus::Pending)
            ->update([
                'status' => PayoutStatus::Processing,
                'attempts' => DB::raw('attempts + 1'),
                'sent_at' => now(),
                'updated_at' => now(),
            ]);

        if ($claimed === 0) {
            return;
        }

        $payout = Payout::findOrFail($payoutId);

        try {
            $result = $this->provider->transfer(
                $payout->idempotency_key,
                $payout->destination_account,
                $payout->amount_minor,
                $payout->currency,
            );
        } catch (Throwable $exception) {
            $this->markUnknown($payout, $exception->getMessage());

            return;
        }

        $this->settle($payout, $result);
    }

    /**
     * Ask the provider what happened to an unknown payout. A "not found" only
     * fails the payout once the grace period has passed, because a transfer
     * that succeeded can take a while to become visible.
     */
    public function resolve(int $payoutId): void
    {
        $payout = Payout::findOrFail($payoutId);

        if ($payout->status !== PayoutStatus::Unknown) {
            return;
        }

        try {
            $result = $this->provider->status($payout->idempotency_key);
        } catch (Throwable $exception) {
            Log::warning('Payout status check failed; will retry.', ['payout_id' => $payoutId, 'error' => $exception->getMessage()]);

            return;
        }

        if ($result->status === TransferStatus::NotFound) {
            $graceEndsAt = $payout->sent_at?->addMinutes((int) config('ledger.not_found_grace_minutes'));

            if ($graceEndsAt !== null && now()->lessThan($graceEndsAt)) {
                return;
            }

            $result = new TransferResult(TransferStatus::Failed, message: 'Provider has no record of the transfer after the grace period.');
        }

        $this->settle($payout, $result);
    }

    /**
     * A payout left in "processing" longer than a worker could take means the
     * worker died after (possibly) calling the provider. Its outcome is unknown.
     *
     * @return int number of payouts moved to unknown
     */
    public function markStaleProcessingAsUnknown(): int
    {
        return Payout::query()
            ->where('status', PayoutStatus::Processing)
            ->where('updated_at', '<', now()->subMinutes((int) config('ledger.stale_processing_minutes')))
            ->update([
                'status' => PayoutStatus::Unknown,
                'last_error' => 'Worker stopped before recording the provider response.',
                'updated_at' => now(),
            ]);
    }

    private function markUnknown(Payout $payout, string $reason): void
    {
        Payout::query()
            ->whereKey($payout->id)
            ->where('status', PayoutStatus::Processing)
            ->update(['status' => PayoutStatus::Unknown, 'last_error' => $reason, 'updated_at' => now()]);
    }

    private function settle(Payout $payout, TransferResult $result): void
    {
        DB::transaction(function () use ($payout, $result) {
            $locked = Payout::query()->lockForUpdate()->findOrFail($payout->id);

            if ($locked->status->isFinal()) {
                if ($locked->status->value !== $result->status->value) {
                    Log::critical('Provider result contradicts a settled payout.', [
                        'payout_id' => $locked->id,
                        'recorded' => $locked->status->value,
                        'provider' => $result->status->value,
                    ]);
                }

                return;
            }

            if ($result->status === TransferStatus::Succeeded) {
                $this->ledger->markReservedAsPaid($locked->instructor_id, $locked->amount_minor);

                $locked->update([
                    'status' => PayoutStatus::Succeeded,
                    'provider_reference' => $result->reference,
                    'last_error' => null,
                    'settled_at' => now(),
                ]);

                return;
            }

            $this->ledger->record(
                instructorId: $locked->instructor_id,
                type: LedgerEntryType::PayoutReversal,
                amountMinor: $locked->amount_minor,
                idempotencyKey: "payout_reversal:{$locked->id}",
                occurredOn: CarbonImmutable::today(),
                payoutId: $locked->id,
            );

            $locked->update([
                'status' => PayoutStatus::Failed,
                'last_error' => $result->message,
                'settled_at' => now(),
            ]);
        });
    }
}
