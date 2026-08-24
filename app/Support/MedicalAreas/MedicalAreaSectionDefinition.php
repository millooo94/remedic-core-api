<?php

namespace App\Support\MedicalAreas;

final class MedicalAreaSectionDefinition
{
    public const DEFINITIONS = [
        'hero' => 'Hero / Area medica',
        'scope' => 'Di cosa si occupa',
        'when_useful' => 'Quando è utile una visita',
        'visit_process' => 'Cosa succede durante la visita',
        'services' => 'Prestazioni',
        'faqs' => 'Domande frequenti',
        'equipe' => 'Équipe',
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
