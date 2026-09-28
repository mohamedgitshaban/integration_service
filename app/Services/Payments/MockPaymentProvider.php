<?php

namespace App\Services\Payments;

use App\Enums\MockTransferOutcome;
use App\Enums\TransferStatus;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Str;

/**
 * A deliberately unreliable stand-in for the payout provider.
 *
 * Each transfer randomly (by configured weights) succeeds, fails permanently,
 * times out after the money already moved, or times out before it moved.
 * A success that timed out only becomes visible to status() after a
 * confirmation delay, like a provider with eventually-consistent reads.
 *
 * The provider's own state lives in the cache so every worker process sees
 * the same "bank". Like real providers it honours idempotency keys, but the
 * application never relies on that: tests assert it calls transfer() at most
 * once per payout.
 */
class MockPaymentProvider implements PaymentProvider
{
    /** @var list<MockTransferOutcome> */
    private array $scriptedOutcomes = [];

    public function __construct(private Repository $cache) {}

    public function transfer(string $idempotencyKey, string $destination, int $amountMinor, string $currency): TransferResult
    {
        $this->cache->increment($this->key('calls', $idempotencyKey));

        if ($existing = $this->record($idempotencyKey)) {
            return $this->toResult($existing);
        }

        return match ($this->nextOutcome()) {
            MockTransferOutcome::Success => $this->toResult(
                $this->store($idempotencyKey, TransferStatus::Succeeded, $amountMinor, visibleAt: now()->getTimestamp()),
            ),
            MockTransferOutcome::PermanentFailure => $this->toResult(
                $this->store($idempotencyKey, TransferStatus::Failed, 0, visibleAt: now()->getTimestamp(), message: 'Beneficiary account closed.'),
            ),
            MockTransferOutcome::TimeoutAfterSuccess => $this->timeoutAfter(
                fn () => $this->store($idempotencyKey, TransferStatus::Succeeded, $amountMinor, visibleAt: now()->getTimestamp() + $this->confirmationDelaySeconds()),
            ),
            MockTransferOutcome::TimeoutBeforeSuccess => $this->timeoutAfter(fn () => null),
        };
    }

    public function status(string $idempotencyKey): TransferResult
    {
        $record = $this->record($idempotencyKey);

        if ($record === null || $record['visible_at'] > now()->getTimestamp()) {
            return new TransferResult(TransferStatus::NotFound);
        }

        return $this->toResult($record);
    }

    /**
     * Force the next transfer outcomes, in order, instead of rolling dice.
     */
    public function willRespond(MockTransferOutcome ...$outcomes): static
    {
        array_push($this->scriptedOutcomes, ...$outcomes);

        return $this;
    }

    /**
     * How many times transfer() was called with this key.
     */
    public function transferCalls(string $idempotencyKey): int
    {
        return (int) $this->cache->get($this->key('calls', $idempotencyKey), 0);
    }

    /**
     * Money actually moved to the beneficiary for this key.
     */
    public function movedMinor(string $idempotencyKey): int
    {
        $record = $this->record($idempotencyKey);

        return $record !== null && $record['status'] === TransferStatus::Succeeded->value ? $record['amount_minor'] : 0;
    }

    private function nextOutcome(): MockTransferOutcome
    {
        if ($this->scriptedOutcomes !== []) {
            return array_shift($this->scriptedOutcomes);
        }

        /** @var array<string, int> $weights */
        $weights = config('ledger.mock_provider.weights');
        $roll = random_int(1, array_sum($weights));

        foreach ($weights as $outcome => $weight) {
            if (($roll -= $weight) <= 0) {
                return MockTransferOutcome::from($outcome);
            }
        }

        return MockTransferOutcome::Success;
    }

    /**
     * @param  callable(): mixed  $sideEffect  what happens at the provider before the connection drops
     */
    private function timeoutAfter(callable $sideEffect): never
    {
        $sideEffect();

        throw new ProviderTimeoutException('Provider did not respond in time; transfer outcome unknown.');
    }

    /**
     * @return array{status: string, reference: string|null, amount_minor: int, visible_at: int, message: string|null}
     */
    private function store(string $idempotencyKey, TransferStatus $status, int $amountMinor, int $visibleAt, ?string $message = null): array
    {
        $record = [
            'status' => $status->value,
            'reference' => $status === TransferStatus::Succeeded ? 'MOCK-'.Str::upper(Str::random(12)) : null,
            'amount_minor' => $amountMinor,
            'visible_at' => $visibleAt,
            'message' => $message,
        ];

        $this->cache->forever($this->key('transfer', $idempotencyKey), $record);

        return $record;
    }

    /**
     * @return array{status: string, reference: string|null, amount_minor: int, visible_at: int, message: string|null}|null
     */
    private function record(string $idempotencyKey): ?array
    {
        return $this->cache->get($this->key('transfer', $idempotencyKey));
    }

    /**
     * @param  array{status: string, reference: string|null, message: string|null}  $record
     */
    private function toResult(array $record): TransferResult
    {
        return new TransferResult(TransferStatus::from($record['status']), $record['reference'], $record['message']);
    }

    private function confirmationDelaySeconds(): int
    {
        return (int) config('ledger.mock_provider.confirmation_delay_seconds');
    }

    private function key(string $kind, string $idempotencyKey): string
    {
        return "mock-provider:{$kind}:{$idempotencyKey}";
    }
}
