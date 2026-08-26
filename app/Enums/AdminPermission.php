<?php

namespace App\Enums;

enum AdminPermission: string
{
    // Keep the historical permission value for database compatibility.
    case VIEW_BACKOFFICE = 'view filament admin';
    case MANAGE_PAGES = 'manage pages';
    case MANAGE_SPECIALIZATIONS = 'manage specializations';
    case MANAGE_SERVICES = 'manage services';
    case MANAGE_DOCTORS = 'manage doctors';
    case MANAGE_PROMOTIONS = 'manage promotions';
    case MANAGE_EVENTS = 'manage events';
    case MANAGE_CAMPAIGNS = 'manage campaigns';
    case MANAGE_CONVENTIONS = 'manage conventions';
    case MANAGE_APPLICATIONS = 'manage applications';
    case MANAGE_BLOG_POSTS = 'manage blog posts';
    case MANAGE_REDIRECTS = 'manage redirects';
    case MANAGE_SETTINGS = 'manage settings';
    case MANAGE_CENTER_SETTINGS = 'manage center settings';
    case MANAGE_SITE_NAVIGATION = 'manage site navigation';
    case MANAGE_SITE_POPUP = 'manage site popup';
    case MANAGE_NEWSLETTER_SUBSCRIBERS = 'manage newsletter subscribers';
    case MANAGE_CONSENT_CONFIGURATION = 'manage consent configuration';
    case VIEW_CONSENT_RECORDS = 'view consent records';
    case MANAGE_USERS = 'manage users';
    case PUBLISH_CONTENT = 'publish content';
    case MANAGE_SEO_FIELDS = 'manage seo fields';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $permission): string => $permission->value,
            self::cases(),
        );
    }
}
