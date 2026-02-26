<?php

namespace App\Policies;

use App\Enums\OrganizationRole;
use App\Models\MediaPack;
use App\Models\Organization;
use App\Models\User;

class MediaPackPolicy
{
    public function viewAny(User $user, Organization $organization): bool
    {
        return $user->isMemberOf($organization);
    }

    public function view(User $user, MediaPack $pack): bool
    {
        return $user->isMemberOf($pack->organization);
    }

    public function create(User $user, Organization $organization): bool
    {
        $role = OrganizationRole::from($user->roleIn($organization));
        return $role->hasPermission('media.upload');
    }

    public function update(User $user, MediaPack $pack): bool
    {
        $role = OrganizationRole::from($user->roleIn($pack->organization));
        if ($role->canManageOthersContent()) return true;
        return $role->hasPermission('media.upload') && $pack->created_by === $user->id;
    }

    public function delete(User $user, MediaPack $pack): bool
    {
        $role = OrganizationRole::from($user->roleIn($pack->organization));
        if ($role->canManageOthersContent()) return true;
        return $role->hasPermission('media.delete.own') && $pack->created_by === $user->id;
    }
}

