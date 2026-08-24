<?php

namespace App\Support\Services;

final class ServiceSectionDefinition
{
    public const DEFINITIONS = [
        'hero' => 'Hero / Prestazione',
        'what_is' => 'Che cos’è la prestazione?',
        'when_to_request' => 'Quando richiederla?',
        'procedure' => 'Come si svolge la prestazione?',
        'preparation' => 'Norme di preparazione e consigli utili',
        'price' => 'Quanto costa',
        'faqs' => 'Domande frequenti',
        'equipe' => 'Équipe per la prestazione',
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
