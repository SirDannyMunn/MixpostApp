<?php

namespace App\Policies;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\Template;
use App\Models\User;

class TemplatePolicy
{
    public function viewAny(User $user, Organization $organization): bool
    {
        return $user->isMemberOf($organization);
    }

    public function view(User $user, Template $template): bool
    {
        return $user->isMemberOf($template->organization);
    }

    public function create(User $user, Organization $organization): bool
    {
        $role = OrganizationRole::from($user->roleIn($organization));
        return $role->hasPermission('templates.create');
    }

    public function update(User $user, Template $template): bool
    {
        $role = OrganizationRole::from($user->roleIn($template->organization));
        if ($role->canManageOthersContent()) {
            return true;
        }
        return $role->hasPermission('templates.update.own') && $template->created_by === $user->id;
    }

    public function delete(User $user, Template $template): bool
    {
        $role = OrganizationRole::from($user->roleIn($template->organization));
        if ($role->canManageOthersContent()) {
            return true;
        }
        return $role->hasPermission('templates.delete.own') && $template->created_by === $user->id;
    }
}

