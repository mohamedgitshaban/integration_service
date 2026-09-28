<?php

namespace Database\Seeders;

use App\Enums\SubscriptionPlan;
use App\Models\Course;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * One subscription per student on a random plan, started within the last
 * eight months, with two to six courses from across the catalogue. The demo
 * student is on an annual plan that includes the demo instructor's courses.
 */
class SubscriptionSeeder extends Seeder
{
    public function run(): void
    {
        $today = CarbonImmutable::today();
        $courseIds = Course::query()->orderBy('id')->pluck('id')->all();

        $demoStudent = User::where('email', 'student@example.com')->first();
        $demoInstructor = User::where('email', 'instructor@example.com')->first();

        if ($demoStudent && $demoInstructor) {
            $this->subscribe($demoStudent, SubscriptionPlan::Annual, $today->subDays(100), [
                ...$demoInstructor->courses()->pluck('id')->all(),
                ...fake()->randomElements($courseIds, 2),
            ]);
        }

        User::query()
            ->where('role', 'student')
            ->whereDoesntHave('subscriptions')
            ->orderBy('id')
            ->each(fn (User $student) => $this->subscribe(
                $student,
                fake()->randomElement(SubscriptionPlan::cases()),
                $today->subDays(fake()->numberBetween(0, 240)),
                fake()->randomElements($courseIds, min(count($courseIds), fake()->numberBetween(2, 6))),
            ));
    }

    /**
     * @param  list<int>  $courseIds
     */
    private function subscribe(User $student, SubscriptionPlan $plan, CarbonImmutable $startsOn, array $courseIds): void
    {
        Subscription::factory()
            ->for($student, 'student')
            ->plan($plan, $startsOn, $plan->priceMinor())
            ->create()
            ->courses()
            ->attach(array_unique($courseIds));
    }
}
