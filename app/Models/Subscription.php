<?php

namespace App\Models;

use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Support\RevenueMath;
use Carbon\CarbonImmutable;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property SubscriptionPlan $plan
 * @property SubscriptionStatus $status
 * @property int $amount_minor
 * @property int $platform_share_bps
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable $ends_on
 * @property int $term_days
 * @property CarbonImmutable $service_ends_on
 * @property CarbonImmutable $recognized_through
 * @property CarbonImmutable|null $refunded_on
 * @property int|null $refund_amount_minor
 */
#[Fillable([
    'user_id', 'plan', 'amount_minor', 'currency', 'payment_reference', 'platform_share_bps', 'starts_on', 'ends_on',
    'term_days', 'service_ends_on', 'recognized_through', 'status', 'refunded_on', 'refund_amount_minor',
])]
class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (Subscription $subscription) {
            $subscription->platform_share_bps ??= config('ledger.platform_share_bps');
            $subscription->service_ends_on ??= $subscription->ends_on;
            $subscription->recognized_through ??= $subscription->starts_on->subDay();
        });
    }

    protected function casts(): array
    {
        return [
            'plan' => SubscriptionPlan::class,
            'status' => SubscriptionStatus::class,
            'amount_minor' => 'integer',
            'platform_share_bps' => 'integer',
            'term_days' => 'integer',
            'refund_amount_minor' => 'integer',
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'service_ends_on' => 'immutable_date',
            'recognized_through' => 'immutable_date',
            'refunded_on' => 'immutable_date',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'subscription_courses')->withTimestamps();
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(RevenueAllocation::class);
    }

    /**
     * Cumulative revenue earned from the start of the term through $day
     * (zero before the start, the full amount from the last day onwards).
     */
    public function earnedThroughMinor(CarbonImmutable $day): int
    {
        $daysServed = (int) $this->starts_on->diffInDays($day->startOfDay(), false) + 1;

        return RevenueMath::earnedAfterDays($this->amount_minor, $this->term_days, $daysServed);
    }

    /**
     * Subscriptions with served days up to $through not yet turned into earnings.
     */
    #[Scope]
    protected function dueForRecognition(Builder $query, CarbonImmutable $through): void
    {
        $query->where('recognized_through', '<', $through->toDateString())
            ->whereColumn('recognized_through', '<', 'service_ends_on');
    }
}
