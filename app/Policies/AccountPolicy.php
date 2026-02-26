<?php

namespace App\Policies;

use App\Enums\OrganizationRole;
use App\Models\Account;
use App\Models\Organization;
use App\Models\User;

/**
 * Policy for Mixpost Account model (extended with organization support).
 */
class AccountPolicy
{
    public function viewAny(User $user, Organization $organization): bool
    {
        return $user->isMemberOf($organization);
    }

    public function view(User $user, Account $account): bool
    {
        if (!$account->organization_id) {
            // Account without organization - allow if user has any org membership
            return true;
        }
        return $user->isMemberOf($account->organization);
    }

    public function create(User $user, Organization $organization): bool
    {
        $role = OrganizationRole::from($user->roleIn($organization));
        return $role->hasPermission('social.connect');
    }

    public function update(User $user, Account $account): bool
    {
        if (!$account->organization_id) {
            return true;
        }
        $role = OrganizationRole::from($user->roleIn($account->organization));
        if ($role->canManageOthersContent()) return true;
        return $account->connected_by === $user->id;
    }

    public function delete(User $user, Account $account): bool
    {
        if (!$account->organization_id) {
            return true;
        }
        $role = OrganizationRole::from($user->roleIn($account->organization));
        if ($role->canManageOthersContent()) return true;
        return $account->connected_by === $user->id;
    }
}
