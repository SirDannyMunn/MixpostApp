<?php

namespace App\Policies;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\SocialAccount;
use App\Models\User;

class SocialAccountPolicy
{
    public function viewAny(User $user, Organization $organization): bool
    {
        return $user->isMemberOf($organization);
    }

    public function view(User $user, SocialAccount $account): bool
    {
        return $user->isMemberOf($account->organization);
    }

    public function create(User $user, Organization $organization): bool
    {
        $role = OrganizationRole::from($user->roleIn($organization));
        return $role->hasPermission('social.connect');
    }

    public function delete(User $user, SocialAccount $account): bool
    {
        $role = OrganizationRole::from($user->roleIn($account->organization));
        if ($role->canManageOthersContent()) return true;
        return $account->connected_by === $user->id;
    }
}

