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

        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return $path;
        }

        $url = Storage::disk('public')->url($path);
        $base = rtrim(self::requestBaseUrl($request), '/');
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

    private static function requestBaseUrl(Request $request): string
    {
        $forwardedHost = self::firstForwardedValue((string) $request->headers->get('x-forwarded-host', ''));
        $forwardedProto = self::firstForwardedValue((string) $request->headers->get('x-forwarded-proto', ''));
        $forwardedPort = self::firstForwardedValue((string) $request->headers->get('x-forwarded-port', ''));

        $host = trim($forwardedHost !== '' ? $forwardedHost : (string) $request->server('HTTP_HOST', $request->getHost()));
        if ($host === '') {
            return $request->getSchemeAndHttpHost();
        }

        $scheme = trim($forwardedProto !== '' ? $forwardedProto : self::requestScheme($request));
        if ($scheme === '') {
            $scheme = 'http';
        }

        if (str_contains($host, '://')) {
            $parsedHost = parse_url($host);
            $host = (string) ($parsedHost['host'] ?? $host);
            $hostPort = isset($parsedHost['port']) ? (int) $parsedHost['port'] : null;
        } else {
            $parsedHost = parse_url($scheme.'://'.$host);
            $host = (string) ($parsedHost['host'] ?? $host);
            $hostPort = isset($parsedHost['port']) ? (int) $parsedHost['port'] : null;
        }

        $port = $hostPort
            ?? self::normalizePort($forwardedPort)
            ?? self::normalizePort((string) $request->server('SERVER_PORT', ''))
            ?? self::defaultPort($scheme);

        $normalizedPort = self::defaultPort($scheme) === $port ? '' : ':'.$port;

        return strtolower($scheme).'://'.$host.$normalizedPort;
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

    private static function firstForwardedValue(string $value): string
    {
        if ($value === '') {
            return '';
        }

        return trim(explode(',', $value)[0] ?? '');
    }

    private static function normalizePort(string $port): ?int
    {
        $port = trim($port);

        if ($port === '' || ! ctype_digit($port)) {
            return null;
        }

        return (int) $port;
    }

    private static function requestScheme(Request $request): string
    {
        $https = strtolower((string) $request->server('HTTPS', ''));

        if ($https === 'on' || $https === '1') {
            return 'https';
        }

        $scheme = trim((string) $request->server('REQUEST_SCHEME', ''));

        if ($scheme !== '') {
            return strtolower($scheme);
        }

        return $request->isSecure() ? 'https' : 'http';
    }
}
