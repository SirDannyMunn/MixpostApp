<?php

namespace App\Policies;

use App\Enums\OrganizationRole;
use App\Models\Bookmark;
use App\Models\Organization;
use App\Models\User;

class BookmarkPolicy
{
    public function viewAny(User $user, Organization $organization): bool
    {
        return $user->isMemberOf($organization);
    }

    public function view(User $user, Bookmark $bookmark): bool
    {
        return $user->isMemberOf($bookmark->organization);
    }

    public function create(User $user, Organization $organization): bool
    {
        $role = OrganizationRole::from($user->roleIn($organization));
        return $role->hasPermission('bookmarks.create');
    }

    public function update(User $user, Bookmark $bookmark): bool
    {
        $role = OrganizationRole::from($user->roleIn($bookmark->organization));
        if ($role->canManageOthersContent()) {
            return true;
        }
        return $role->hasPermission('bookmarks.update.own') && $bookmark->created_by === $user->id;
    }

    public function delete(User $user, Bookmark $bookmark): bool
    {
        $role = OrganizationRole::from($user->roleIn($bookmark->organization));
        if ($role->canManageOthersContent()) {
            return true;
        }
        return $role->hasPermission('bookmarks.delete.own') && $bookmark->created_by === $user->id;
    }
}

