<?php

namespace App\Policies;

use App\Enums\OrganizationRole;
use App\Models\MediaImage;
use App\Models\Organization;
use App\Models\User;

class MediaImagePolicy
{
    public function viewAny(User $user, Organization $organization): bool
    {
        return $user->isMemberOf($organization);
    }

    public function view(User $user, MediaImage $image): bool
    {
        return $user->isMemberOf($image->organization);
    }

    public function create(User $user, Organization $organization): bool
    {
        $role = OrganizationRole::from($user->roleIn($organization));
        return $role->hasPermission('media.upload');
    }

    public function update(User $user, MediaImage $image): bool
    {
        $role = OrganizationRole::from($user->roleIn($image->organization));
        if ($role->canManageOthersContent()) return true;
        return $role->hasPermission('media.upload') && $image->uploaded_by === $user->id;
    }

    public function delete(User $user, MediaImage $image): bool
    {
        $role = OrganizationRole::from($user->roleIn($image->organization));
        if ($role->canManageOthersContent()) return true;
        return $role->hasPermission('media.delete.own') && $image->uploaded_by === $user->id;
    }
}

