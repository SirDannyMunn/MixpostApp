<?php

namespace App\Enums;

enum OrganizationRole: string
{
    case OWNER = 'owner';
    case ADMIN = 'admin';
    case MEMBER = 'member';
    case VIEWER = 'viewer';

    public function permissions(): array
    {
        return match($this) {
            self::OWNER => [
                'organization.delete', 'organization.update', 'members.invite', 'members.remove', 'members.update_role', 'billing.manage', 'manage_swipe_structures', 'all_content_permissions',
            ],
            self::ADMIN => [
                'organization.update', 'members.invite', 'members.remove', 'manage_swipe_structures', 'all_content_permissions',
            ],
            self::MEMBER => [
                'bookmarks.view', 'bookmarks.create', 'bookmarks.update.own', 'bookmarks.delete.own',
                'templates.view', 'templates.create', 'templates.update.own', 'templates.delete.own',
                'projects.view', 'projects.create', 'projects.update.own', 'projects.delete.own',
                'media.view', 'media.upload', 'media.delete.own',
                'social.connect', 'posts.schedule',
                'knowledge.view', 'knowledge.edit', 'knowledge.deactivate', 'knowledge.reclassify', 'knowledge.set_policy',
                'research.search', 'research.add_to_knowledge',
            ],
            self::VIEWER => [
                'bookmarks.view', 'templates.view', 'projects.view', 'media.view', 'analytics.view',
                'knowledge.view', 'research.search',
            ],
        };
    }

    public function hasPermission(string $permission): bool
    {
        $permissions = $this->permissions();
        if (in_array('all_content_permissions', $permissions)) {
            return true;
        }
        return in_array($permission, $permissions);
    }

    public function canManageOthersContent(): bool
    {
        return in_array($this, [self::OWNER, self::ADMIN]);
    }
}
