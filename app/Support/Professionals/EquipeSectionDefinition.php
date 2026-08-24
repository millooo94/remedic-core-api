<?php

namespace App\Support\Professionals;

final class EquipeSectionDefinition
{
    public const DEFINITIONS = [
        'hero' => 'Hero / Profilo professionale',
        'biography' => 'Biografia',
        'approach' => 'Il mio approccio',
        'competencies' => 'Competenze cliniche',
        'career' => 'Percorso professionale',
        'scientific_activity' => 'Attività scientifica',
        'services' => 'Prestazioni',
    ];

    public static function keys(): array
    {
        return array_keys(self::DEFINITIONS);
    }
}
