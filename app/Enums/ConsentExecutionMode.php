<?php

namespace App\Enums;

enum ConsentExecutionMode: string
{
    case SCRIPT = 'script';
    case IFRAME = 'iframe';
    case EMBED = 'embed';
    case TAG_MANAGER = 'tag_manager';
    case CUSTOM = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::SCRIPT => 'Script',
            self::IFRAME => 'Iframe',
            self::EMBED => 'Embed',
            self::TAG_MANAGER => 'Tag manager',
            self::CUSTOM => 'Custom',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
