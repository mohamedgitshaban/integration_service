<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'role', 'bank_account_number', 'vodafone_cash_number'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    use HasApiTokens, HasFactory, Notifiable;

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Only platform admins may open the Filament panel.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->role === 'admin';
    }

    // --- Relationships ---

    /**
     * Courses taught by this user (if instructor).
     */
    public function courses(): HasMany
    {
        return $this->hasMany(Course::class, 'instructor_id');
    }

    /**
     * Subscriptions purchased by this user (if student).
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'user_id');
    }

    /**
     * Ledger movements (earnings, clawbacks, payouts) for this instructor.
     */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class, 'instructor_id');
    }

    /**
     * Financial balance record for this instructor.
     */
    public function balance(): HasOne
    {
        return $this->hasOne(InstructorBalance::class, 'instructor_id');
    }

    /**
     * Payouts sent to this instructor.
     */
    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class, 'instructor_id');
    }
}
