<?php

namespace App\Support\Pages;

/**
 * Closed contract for future typed Page sections.
 *
 * Definitions are intentionally empty until the real institutional pages are
 * modelled from the public site. Existing Page sections remain compatible in
 * the meantime.
 */
final class PageSectionRegistry
{
    /**
     * @var array<string, array<string, array{label: string, capabilities?: list<string>}>>
     */
    private const DEFINITIONS = [];

    /** @return array{label: string, capabilities?: list<string>}|null */
    public static function definition(string $internalKey, string $sectionKey): ?array
    {
        return self::DEFINITIONS[$internalKey][$sectionKey] ?? null;
    }

    public static function hasDefinitionsFor(string $internalKey): bool
    {
        return array_key_exists($internalKey, self::DEFINITIONS);
    }

    public static function canCreate(string $internalKey, string $sectionKey): bool
    {
        // Keep current and legacy pages compatible until a closed definition
        // is intentionally registered for their internal_key.
        if (! self::hasDefinitionsFor($internalKey)) {
            return true;
        }

        return self::definition($internalKey, $sectionKey) !== null;
    }
}
