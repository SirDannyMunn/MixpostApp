<?php

namespace App\Services;

use App\Models\Organization;

class SubscriptionService
{
    protected array $limits = [
        'free' => [
            'bookmarks' => 100,
            'templates' => 10,
            'projects' => 5,
            'media_storage_gb' => 1,
            'social_accounts' => 2,
            'team_members' => 1,
            'ai_generations_per_month' => 10,
        ],
        'pro' => [
            'bookmarks' => 1000,
            'templates' => 100,
            'projects' => 50,
            'media_storage_gb' => 50,
            'social_accounts' => 10,
            'team_members' => 10,
            'ai_generations_per_month' => 100,
        ],
        'enterprise' => [
            'bookmarks' => PHP_INT_MAX,
            'templates' => PHP_INT_MAX,
            'projects' => PHP_INT_MAX,
            'media_storage_gb' => 500,
            'social_accounts' => 50,
            'team_members' => PHP_INT_MAX,
            'ai_generations_per_month' => 1000,
        ],
    ];

    public function canCreate(Organization $organization, string $resource): bool
    {
        $tier = $organization->subscription_tier;
        $limit = $this->limits[$tier][$resource] ?? 0;
        if ($limit === PHP_INT_MAX) {
            return true;
        }
        $current = $this->getCurrentCount($organization, $resource);
        return $current < $limit;
    }

    public function getRemainingQuota(Organization $organization, string $resource): int
    {
        $tier = $organization->subscription_tier;
        $limit = $this->limits[$tier][$resource] ?? 0;
        $current = $this->getCurrentCount($organization, $resource);
        return max(0, $limit - $current);
    }

    protected function getCurrentCount(Organization $organization, string $resource): int
    {
        return match($resource) {
            'bookmarks' => $organization->bookmarks()->count(),
            'templates' => $organization->templates()->count(),
            'projects' => $organization->projects()->count(),
            'social_accounts' => $organization->socialAccounts()->count(),
            'team_members' => $organization->members()->count(),
            'ai_generations_per_month' => 0, // Placeholder
            'media_storage_gb' => 0, // Placeholder
            default => 0,
        };
    }
}

