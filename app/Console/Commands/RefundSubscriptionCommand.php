<?php

namespace App\Console\Commands;

use App\Enums\RefundPolicy;
use App\Services\RefundService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('subscriptions:refund {subscription : Subscription id} {--on= : Effective date (Y-m-d), defaults to today} {--full : Return the whole payment and claw back all instructor earnings}')]
#[Description('Refund a subscription, pro-rata by default.')]
class RefundSubscriptionCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(RefundService $refunds): int
    {
        try {
            $subscription = $refunds->refund(
                (int) $this->argument('subscription'),
                $this->option('on') ? CarbonImmutable::parse($this->option('on')) : CarbonImmutable::today(),
                $this->option('full') ? RefundPolicy::Full : RefundPolicy::ProRata,
            );
        } catch (DomainException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Subscription %d refunded %s piastres; service ended %s.',
            $subscription->id,
            number_format($subscription->refund_amount_minor),
            $subscription->service_ends_on->toDateString(),
        ));

        return self::SUCCESS;
    }
}
