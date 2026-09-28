<?php

namespace Tests\Feature;

use App\Enums\LedgerEntryType;
use App\Enums\SubscriptionPlan;
use App\Models\LedgerEntry;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class LedgerSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_ledger_entries_cannot_be_updated(): void
    {
        $entry = $this->makeEntry('earning:1:1');

        $this->expectException(LogicException::class);

        $entry->update(['amount_minor' => 1]);
    }

    public function test_ledger_entries_cannot_be_deleted(): void
    {
        $entry = $this->makeEntry('earning:1:1');

        $this->expectException(LogicException::class);

        $entry->delete();
    }

    public function test_the_same_business_event_cannot_be_recorded_twice(): void
    {
        $this->makeEntry('earning:1:1');

        $this->expectException(QueryException::class);

        $this->makeEntry('earning:1:1');
    }

    public function test_a_new_subscription_snapshots_the_platform_share_and_starts_with_nothing_recognised(): void
    {
        config(['ledger.platform_share_bps' => 2500]);

        $subscription = Subscription::factory()
            ->plan(SubscriptionPlan::Quarterly, CarbonImmutable::parse('2026-01-01'))
            ->create();

        config(['ledger.platform_share_bps' => 4000]);

        $subscription->refresh();
        $this->assertSame(2500, $subscription->platform_share_bps);
        $this->assertSame('2026-03-31', $subscription->service_ends_on->toDateString());
        $this->assertSame('2025-12-31', $subscription->recognized_through->toDateString());
    }

    private function makeEntry(string $idempotencyKey): LedgerEntry
    {
        return LedgerEntry::create([
            'instructor_id' => User::factory()->instructor()->create()->id,
            'type' => LedgerEntryType::Earning,
            'amount_minor' => 1_000,
            'idempotency_key' => $idempotencyKey,
            'occurred_on' => '2026-01-01',
        ]);
    }
}
