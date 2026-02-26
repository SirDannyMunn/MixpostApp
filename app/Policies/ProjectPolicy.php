<?php

namespace App\Policies;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    public function viewAny(User $user, Organization $organization): bool
    {
        return $user->isMemberOf($organization);
    }

    public function view(User $user, Project $project): bool
    {
        return $user->isMemberOf($project->organization);
    }

    public function create(User $user, Organization $organization): bool
    {
        $role = OrganizationRole::from($user->roleIn($organization));
        return $role->hasPermission('projects.create');
    }

    public function update(User $user, Project $project): bool
    {
        $role = OrganizationRole::from($user->roleIn($project->organization));
        if ($role->canManageOthersContent()) return true;
        return $role->hasPermission('projects.update.own') && $project->created_by === $user->id;
    }

    public function delete(User $user, Project $project): bool
    {
        $role = OrganizationRole::from($user->roleIn($project->organization));
        if ($role->canManageOthersContent()) return true;
        return $role->hasPermission('projects.delete.own') && $project->created_by === $user->id;
    }
}

