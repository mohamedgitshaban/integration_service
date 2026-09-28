<?php

namespace App\Policies;

use App\Models\Subscription;
use App\Models\User;

class SubscriptionPolicy
{
    /**
     * Students see only their own subscriptions.
     */
    public function view(User $user, Subscription $subscription): bool
    {
        return $subscription->user_id === $user->id;
    }

    /**
     * Adding or removing courses on the subscription.
     */
    public function update(User $user, Subscription $subscription): bool
    {
        return $subscription->user_id === $user->id;
    }

    /**
     * Students may request a pro-rata refund of their own subscription.
     */
    public function refund(User $user, Subscription $subscription): bool
    {
        return $subscription->user_id === $user->id;
    }
}
