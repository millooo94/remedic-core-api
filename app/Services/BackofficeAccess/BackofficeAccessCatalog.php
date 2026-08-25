<?php

namespace App\Services\BackofficeAccess;

use App\Enums\AdminPermission;
use App\Enums\AdminRole;

final class BackofficeAccessCatalog
{
    public const GUARD_NAME = 'web';

    /**
     * @return list<string>
     */
    public function permissions(): array
    {
        return AdminPermission::values();
    }

    /**
     * @return list<string>
     */
    public function roles(): array
    {
        return array_keys($this->rolePermissions());
    }

    /**
     * @return array<string, list<string>>
     */
    public function rolePermissions(): array
    {
        return [
            AdminRole::SUPER_ADMIN->value => $this->permissions(),
            AdminRole::ADMIN->value => [
                AdminPermission::VIEW_BACKOFFICE->value,
                AdminPermission::MANAGE_PAGES->value,
                AdminPermission::MANAGE_SPECIALIZATIONS->value,
                AdminPermission::MANAGE_SERVICES->value,
                AdminPermission::MANAGE_DOCTORS->value,
                AdminPermission::MANAGE_CONVENTIONS->value,
                AdminPermission::MANAGE_BLOG_POSTS->value,
                AdminPermission::MANAGE_SETTINGS->value,
                AdminPermission::MANAGE_CENTER_SETTINGS->value,
                AdminPermission::MANAGE_CONSENT_CONFIGURATION->value,
                AdminPermission::VIEW_CONSENT_RECORDS->value,
                AdminPermission::MANAGE_USERS->value,
                AdminPermission::PUBLISH_CONTENT->value,
                AdminPermission::MANAGE_SEO_FIELDS->value,
            ],
            AdminRole::EDITOR->value => [
                AdminPermission::VIEW_BACKOFFICE->value,
                AdminPermission::MANAGE_PAGES->value,
                AdminPermission::MANAGE_SPECIALIZATIONS->value,
                AdminPermission::MANAGE_SERVICES->value,
                AdminPermission::MANAGE_DOCTORS->value,
                AdminPermission::MANAGE_CONVENTIONS->value,
                AdminPermission::MANAGE_BLOG_POSTS->value,
            ],
            AdminRole::SEO_MANAGER->value => [
                AdminPermission::VIEW_BACKOFFICE->value,
                AdminPermission::MANAGE_PAGES->value,
                AdminPermission::MANAGE_SPECIALIZATIONS->value,
                AdminPermission::MANAGE_SERVICES->value,
                AdminPermission::MANAGE_DOCTORS->value,
                AdminPermission::MANAGE_CONVENTIONS->value,
                AdminPermission::MANAGE_BLOG_POSTS->value,
                AdminPermission::MANAGE_REDIRECTS->value,
                AdminPermission::MANAGE_SETTINGS->value,
                AdminPermission::MANAGE_CONSENT_CONFIGURATION->value,
                AdminPermission::PUBLISH_CONTENT->value,
                AdminPermission::MANAGE_SEO_FIELDS->value,
            ],
        ];
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            'guard' => self::GUARD_NAME,
            'roles' => $this->rolePermissions(),
        ], JSON_THROW_ON_ERROR));
    }
}
