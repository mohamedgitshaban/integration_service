<?php

namespace App\Services;

use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Models\Course;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turns a completed checkout into a subscription and manages the courses on it.
 *
 * The card is charged by the checkout provider before this runs; the client
 * sends its payment reference. That reference is unique, so a replayed
 * request returns the original subscription instead of creating a second one.
 * The price always comes from config, never from the request.
 */
class SubscriptionService
{
    /**
     * @param  list<int>  $courseIds
     * @return array{Subscription, bool} the subscription, and whether it was created by this call
     *
     * @throws ValidationException
     */
    public function purchase(User $student, SubscriptionPlan $plan, array $courseIds, string $paymentReference): array
    {
        if ($existing = Subscription::firstWhere('payment_reference', $paymentReference)) {
            return $this->replay($existing, $student);
        }

        try {
            return DB::transaction(function () use ($student, $plan, $courseIds, $paymentReference) {
                // Serialises concurrent purchases by the same student.
                User::query()->whereKey($student->id)->lockForUpdate()->first();

                if ($this->hasSubscriptionInService($student)) {
                    throw ValidationException::withMessages(['plan' => 'You already have a subscription in service.']);
                }

                $startsOn = CarbonImmutable::today();
                $subscription = Subscription::create([
                    'user_id' => $student->id,
                    'plan' => $plan,
                    'amount_minor' => $plan->priceMinor(),
                    'currency' => config('ledger.currency'),
                    'payment_reference' => $paymentReference,
                    'starts_on' => $startsOn,
                    'ends_on' => $plan->endsOn($startsOn),
                    'term_days' => $plan->termDays($startsOn),
                    'status' => SubscriptionStatus::Active,
                ]);

                $subscription->courses()->attach(array_values(array_unique($courseIds)));

                return [$subscription, true];
            });
        } catch (UniqueConstraintViolationException) {
            return $this->replay(Subscription::where('payment_reference', $paymentReference)->firstOrFail(), $student);
        }
    }

    /**
     * Revenue for future days is split using the courses on the subscription
     * when those days are allocated; days already allocated are unaffected.
     *
     * @throws ValidationException
     */
    public function addCourse(Subscription $subscription, Course $course): void
    {
        $this->assertInService($subscription);

        $subscription->courses()->syncWithoutDetaching([$course->id]);
    }

    /**
     * @throws ValidationException
     */
    public function removeCourse(Subscription $subscription, Course $course): void
    {
        $this->assertInService($subscription);

        if ($subscription->courses()->count() <= 1) {
            throw ValidationException::withMessages(['course' => 'A subscription must keep at least one course.']);
        }

        $subscription->courses()->detach($course->id);
    }

    public function isInService(Subscription $subscription): bool
    {
        return $subscription->status === SubscriptionStatus::Active
            && $subscription->service_ends_on->greaterThanOrEqualTo(CarbonImmutable::today());
    }

    private function hasSubscriptionInService(User $student): bool
    {
        return $student->subscriptions()
            ->where('status', SubscriptionStatus::Active)
            ->where('service_ends_on', '>=', CarbonImmutable::today()->toDateString())
            ->exists();
    }

    /**
     * @throws ValidationException
     */
    private function assertInService(Subscription $subscription): void
    {
        if (! $this->isInService($subscription)) {
            throw ValidationException::withMessages(['subscription' => 'Courses can only be changed while the subscription is in service.']);
        }
    }

    /**
     * @return array{Subscription, bool}
     *
     * @throws ValidationException
     */
    private function replay(Subscription $existing, User $student): array
    {
        if ($existing->user_id !== $student->id) {
            throw ValidationException::withMessages(['payment_reference' => 'This payment reference has already been used.']);
        }

        return [$existing, false];
    }
}
