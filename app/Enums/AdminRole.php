<?php

namespace App\Enums;

enum AdminRole: string
{
    case SUPER_ADMIN = 'super_admin';
    case ADMIN = 'admin';
    case EDITOR = 'editor';
    case SEO_MANAGER = 'seo_manager';

    public function label(): string
    {
        return match ($this) {
            self::SUPER_ADMIN => 'Super admin',
            self::ADMIN => 'Admin',
            self::EDITOR => 'Editor',
            self::SEO_MANAGER => 'SEO manager',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(bool $includeSuperAdmin = true): array
    {
        $roles = self::cases();

        if (! $includeSuperAdmin) {
            $roles = array_values(array_filter(
                $roles,
                static fn (self $role): bool => $role !== self::SUPER_ADMIN,
            ));
        }

        $options = [];

        foreach ($roles as $role) {
            $options[$role->value] = $role->label();
        }

        return $options;
    }
}
