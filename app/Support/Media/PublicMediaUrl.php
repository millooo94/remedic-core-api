<?php

namespace App\Support\Media;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PublicMediaUrl
{
    public static function fromPublicDisk(?string $path, Request $request): ?string
    {
        if (! $path) {
            return null;
        }

        $url = Storage::disk('public')->url($path);
        $base = rtrim($request->getSchemeAndHttpHost(), '/');
        if ($base === '') {
            return $url;
        }

        $parsed = parse_url($url);
        if ($parsed !== false && isset($parsed['host'])) {
            $requestHost = parse_url($base, PHP_URL_HOST);
            $requestPort = parse_url($base, PHP_URL_PORT) ?: self::defaultPort((string) parse_url($base, PHP_URL_SCHEME));
            $urlPort = $parsed['port'] ?? self::defaultPort((string) ($parsed['scheme'] ?? 'http'));

            if (
                strcasecmp((string) $parsed['host'], (string) $requestHost) === 0
                && (int) $urlPort === (int) $requestPort
            ) {
                return $url;
            }

            $pathWithSuffix = self::pathWithSuffix($parsed);
            if ($pathWithSuffix !== null && str_starts_with($pathWithSuffix, '/storage/')) {
                return $base.$pathWithSuffix;
            }

            return $url;
        }

        if (str_starts_with($url, '/')) {
            return $base.$url;
        }

        return $base.'/'.$url;
    }

    private static function pathWithSuffix(array $parsed): ?string
    {
        $path = $parsed['path'] ?? null;
        if (! is_string($path) || $path === '') {
            return null;
        }

        $suffix = '';
        if (isset($parsed['query']) && $parsed['query'] !== '') {
            $suffix .= '?'.$parsed['query'];
        }
        if (isset($parsed['fragment']) && $parsed['fragment'] !== '') {
            $suffix .= '#'.$parsed['fragment'];
        }

        return $path.$suffix;
    }

    private static function defaultPort(string $scheme): int
    {
        return strtolower($scheme) === 'https' ? 443 : 80;
    }
}
