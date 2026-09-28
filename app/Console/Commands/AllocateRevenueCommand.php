<?php

namespace App\Console\Commands;

use App\Jobs\AllocateRevenueJob;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('revenue:allocate {--through= : Last day to recognise (Y-m-d). Defaults to yesterday, the last fully served day.} {--chunk=1000 : Subscriptions per queued job}')]
#[Description('Queue revenue recognition for every subscription with served days not yet allocated.')]
class AllocateRevenueCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $through = $this->option('through')
            ? CarbonImmutable::parse($this->option('through'))->startOfDay()
            : CarbonImmutable::yesterday();

        $jobs = 0;

        Subscription::query()
            ->dueForRecognition($through)
            ->select('id')
            ->chunkById((int) $this->option('chunk'), function ($subscriptions) use ($through, &$jobs) {
                AllocateRevenueJob::dispatch($subscriptions->pluck('id')->all(), $through->toDateString());
                $jobs++;
            });

        $this->info("Queued {$jobs} allocation job(s) through {$through->toDateString()}.");

        return self::SUCCESS;
    }
}
