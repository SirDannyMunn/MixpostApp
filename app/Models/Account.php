<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Inovector\Mixpost\Models\Account as MixpostAccount;

/**
 * Extended Mixpost Account model with organization support.
 * 
 * This extends the base Mixpost Account model to add:
 * - organization_id: Multi-tenant organization context
 * - connected_by: User who connected this account
 * - connected_at: Timestamp when the account was connected
 */
class Account extends MixpostAccount
{
    /**
     * Additional fillable fields beyond the parent model.
     */
    protected $fillable = [
        'name',
        'username',
        'media',
        'provider',
        'provider_id',
        'data',
        'authorized',
        'access_token',
        // Extended fields
        'organization_id',
        'connected_by',
        'connected_at',
    ];

    /**
     * Additional casts for extended fields.
     */
    protected $casts = [
        'media' => \Inovector\Mixpost\Casts\AccountMediaCast::class,
        'data' => 'array',
        'authorized' => 'boolean',
        'access_token' => \Inovector\Mixpost\Casts\EncryptArrayObject::class,
        'connected_at' => 'datetime',
    ];

    /**
     * Get the organization this account belongs to.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Scope query to a specific organization.
     */
    public function scopeForOrganization(Builder $query, string|int|null $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    /**
     * Get the user who connected this account.
     */
    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by');
    }

    /**
     * Get values for API responses (extended to include organization context).
     */
    public function values(): array
    {
        return array_merge(parent::values(), [
            'organization_id' => $this->organization_id,
            'connected_by' => $this->connected_by,
            'connected_at' => $this->connected_at?->toISOString(),
        ]);
    }
}
