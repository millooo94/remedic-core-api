<?php

namespace App\Support\Checkups;

final class CheckupSectionDefinition
{
    public const DEFINITIONS = [
        'hero' => 'Hero / Check-up',
        'what_is' => 'Che cos\'è il Check-up?',
        'included_services' => 'Prestazioni incluse',
        'target' => 'A chi è rivolto',
        'procedure' => 'Come si svolge',
        'preparation' => 'Preparazione',
        'price' => 'Quanto costa',
        'faqs' => 'Domande frequenti',
        'equipe' => 'Équipe',
        'related_checkups' => 'Check-up correlati',
    ];

    public const ICON_KEYS = [
        'activity', 'calendar', 'check', 'clock', 'heart', 'info', 'medical',
        'prevention', 'professionals', 'search', 'shield', 'success', 'user',
    ];

    public static function keys(): array
    {
        return array_keys(self::DEFINITIONS);
    }
}
