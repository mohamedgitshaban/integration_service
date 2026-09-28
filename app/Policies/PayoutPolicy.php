<?php

namespace App\Policies;

use App\Models\Payout;
use App\Models\User;

class PayoutPolicy
{
    /**
     * Instructors see only their own payouts; admins see all (Filament panel).
     */
    public function view(User $user, Payout $payout): bool
    {
        return $user->role === 'admin' || $payout->instructor_id === $user->id;
    }
}
