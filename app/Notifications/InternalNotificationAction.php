<?php

namespace App\Notifications;

use InvalidArgumentException;

final readonly class InternalNotificationAction
{
    /** @var array<string, string> */
    private const ROUTES = [
        'dashboard' => '/dashboard',
        'professionals' => '/professionals',
        'services' => '/services',
        'marketing' => '/marketing',
        'settings' => '/settings',
        'profile' => '/profile',
        'web_admin' => '/admin',
        'career_applications' => '/applications',
    ];

    /** @param array<string, scalar> $params */
    public function __construct(public string $route, public array $params = [])
    {
        if (! array_key_exists($route, self::ROUTES)) {
            throw new InvalidArgumentException('The notification action route is not allowed.');
        }

        foreach ($params as $key => $value) {
            if (! is_string($key) || ! preg_match('/^[a-z][a-z0-9_]{0,39}$/', $key) || ! is_scalar($value)) {
                throw new InvalidArgumentException('The notification action parameters are invalid.');
            }
        }
    }

    /** @return array{route:string,params:array<string,scalar>} */
    public function toArray(): array
    {
        return ['route' => $this->route, 'params' => $this->params];
    }

    public static function path(string $route): string
    {
        if (! array_key_exists($route, self::ROUTES)) {
            throw new InvalidArgumentException('The notification action route is not allowed.');
        }

        return self::ROUTES[$route];
    }
}
