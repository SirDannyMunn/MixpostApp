<?php

namespace App\Policies;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\ScheduledPost;
use App\Models\User;

class ScheduledPostPolicy
{
    public function viewAny(User $user, Organization $organization): bool
    {
        return $user->isMemberOf($organization);
    }

    public function view(User $user, ScheduledPost $post): bool
    {
        return $user->isMemberOf($post->organization);
    }

    public function create(User $user, Organization $organization): bool
    {
        $role = OrganizationRole::from($user->roleIn($organization));
        return $role->hasPermission('posts.schedule');
    }

    public function update(User $user, ScheduledPost $post): bool
    {
        $role = OrganizationRole::from($user->roleIn($post->organization));
        if ($role->canManageOthersContent()) return true;
        return $post->created_by === $user->id;
    }

    public function delete(User $user, ScheduledPost $post): bool
    {
        $role = OrganizationRole::from($user->roleIn($post->organization));
        if ($role->canManageOthersContent()) return true;
        return $post->created_by === $user->id;
    }

    public function cancel(User $user, ScheduledPost $post): bool
    {
        return $this->update($user, $post);
    }
}

