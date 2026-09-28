<?php

namespace App\Services;

use App\Enums\AllocationKind;
use App\Enums\LedgerEntryType;
use App\Enums\RefundPolicy;
use App\Enums\SubscriptionStatus;
use App\Models\LedgerEntry;
use App\Models\RevenueAllocation;
use App\Models\Subscription;
use App\Support\RevenueMath;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Ends a subscription early and works out what the student gets back.
 *
 * Days before the effective date were served and stay earned, so a normal
 * refund never touches instructors: the unserved remainder was never
 * allocated to them. Only when recognised days stop counting as served
 * (a backdated or full refund) are earnings reversed, as clawback entries.
 * A clawback larger than the unpaid balance leaves it negative, and the debt
 * is recovered from the instructor's future earnings before any new payout.
 */
class RefundService
{
    public function __construct(private LedgerService $ledger) {}

    /**
     * Idempotent: refunding an already-refunded subscription returns it unchanged.
     *
     * @throws DomainException when the term was fully served and nothing is refundable
     */
    public function refund(int $subscriptionId, CarbonImmutable $effectiveOn, RefundPolicy $policy = RefundPolicy::ProRata): Subscription
    {
        return DB::transaction(function () use ($subscriptionId, $effectiveOn, $policy) {
            $subscription = Subscription::query()->lockForUpdate()->findOrFail($subscriptionId);

            if ($subscription->status === SubscriptionStatus::Refunded) {
                return $subscription;
            }

            $beforeStart = $subscription->starts_on->subDay();
            $serviceEndsOn = match ($policy) {
                RefundPolicy::ProRata => $effectiveOn->startOfDay()->subDay()->min($subscription->ends_on)->max($beforeStart),
                RefundPolicy::Full => $beforeStart,
            };

            $refundMinor = $subscription->amount_minor - $subscription->earnedThroughMinor($serviceEndsOn);

            if ($refundMinor === 0) {
                throw new DomainException("Subscription {$subscription->id} was fully served; nothing to refund.");
            }

            if ($subscription->recognized_through->greaterThan($serviceEndsOn)) {
                $this->reverseRecognisedDays($subscription, $serviceEndsOn);
            }

            $subscription->update([
                'status' => SubscriptionStatus::Refunded,
                'refunded_on' => $effectiveOn->startOfDay(),
                'refund_amount_minor' => $refundMinor,
                'service_ends_on' => $serviceEndsOn,
                'recognized_through' => $subscription->recognized_through->min($serviceEndsOn),
            ]);

            return $subscription;
        });
    }

    /**
     * Un-earn the days after $serviceEndsOn that were already recognised.
     *
     * The platform cut is reversed cumulatively, like recognition. The
     * instructor part is split in proportion to what each instructor has
     * earned from this subscription, so no one loses more than they received
     * from it and a full refund reverses every earning exactly.
     */
    private function reverseRecognisedDays(Subscription $subscription, CarbonImmutable $serviceEndsOn): void
    {
        $bps = $subscription->platform_share_bps;
        $earnedNow = $subscription->earnedThroughMinor($subscription->recognized_through);
        $earnedKept = $subscription->earnedThroughMinor($serviceEndsOn);

        $grossMinor = $earnedNow - $earnedKept;
        $platformMinor = RevenueMath::platformCut($earnedNow, $bps) - RevenueMath::platformCut($earnedKept, $bps);

        $netEarned = $this->netEarnedByInstructor($subscription);
        $poolMinor = min($grossMinor - $platformMinor, array_sum($netEarned));

        $allocation = RevenueAllocation::create([
            'subscription_id' => $subscription->id,
            'kind' => AllocationKind::Reversal,
            'period_start' => $serviceEndsOn->addDay(),
            'period_end' => $subscription->recognized_through,
            'gross_minor' => $grossMinor,
            'platform_minor' => $grossMinor - $poolMinor,
            'instructor_pool_minor' => $poolMinor,
        ]);

        foreach (RevenueMath::splitByWeight($poolMinor, $netEarned) as $instructorId => $amountMinor) {
            if ($amountMinor === 0) {
                continue;
            }

            $this->ledger->record(
                instructorId: $instructorId,
                type: LedgerEntryType::Clawback,
                amountMinor: -$amountMinor,
                idempotencyKey: "clawback:{$allocation->id}:{$instructorId}",
                occurredOn: CarbonImmutable::today(),
                revenueAllocationId: $allocation->id,
            );
        }
    }

    /**
     * Instructor id => earnings net of clawbacks from this subscription, positive only.
     *
     * @return array<int, int>
     */
    private function netEarnedByInstructor(Subscription $subscription): array
    {
        return LedgerEntry::query()
            ->join('revenue_allocations', 'revenue_allocations.id', '=', 'ledger_entries.revenue_allocation_id')
            ->where('revenue_allocations.subscription_id', $subscription->id)
            ->whereIn('ledger_entries.type', [LedgerEntryType::Earning, LedgerEntryType::Clawback])
            ->groupBy('ledger_entries.instructor_id')
            ->selectRaw('ledger_entries.instructor_id, sum(ledger_entries.amount_minor) as net_minor')
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $row) => [(int) $row->instructor_id => (int) $row->net_minor])
            ->filter(fn (int $netMinor) => $netMinor > 0)
            ->all();
    }
}
