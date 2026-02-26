<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Cashier\Billable as CashierBillable;
use Vendor\LaravelBilling\Traits\Billable as BillingPackageBillable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes, HasUuids;
    use CashierBillable, BillingPackageBillable;

    public $incrementing = false;
    protected $keyType = 'string';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'avatar_url',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    // Relationships
    public function organizations()
    {
        return $this->belongsToMany(Organization::class, 'organization_members')
            ->withPivot('role', 'joined_at')
            ->withTimestamps();
    }

    public function organizationMemberships()
    {
        return $this->hasMany(OrganizationMember::class);
    }

    // Helpers
    public function isMemberOf(Organization $organization): bool
    {
        return $this->organizations()->where('organizations.id', $organization->id)->exists();
    }

    public function roleIn(Organization $organization): ?string
    {
        $membership = $this->organizationMemberships()
            ->where('organization_id', $organization->id)
            ->first();
        return $membership?->role;
    }

    /**
     * Determine if the user currently has active access to paid features.
     *
     * Canonical rule for app-wide paywall:
     *  - Active subscription OR on trial (default)
     *  - Optionally allow credits to unlock access (commented)
     */
    public function hasActiveAccess(): bool
    {
        // 1) Entitlement-based access (offline coupon/admin grants)
        try {
            if ($this->billing()->currentPlanCode()) {
                return true;
            }
        } catch (\Throwable $e) {
            // fall through to subscription checks
        }

        // 2) Subscription state via Cashier
        if ($this->subscribed('default')) {
            return true;
        }

        if ($this->onTrial('default')) {
            return true;
        }

        // 3) Optional: credits unlock (disabled by default)
        // if ($this->billing()->hasCredits(1)) {
        //     return true;
        // }

        return false;
    }
}
