<?php

namespace App\Services;

use App\Models\ApplicationType;

class ApplicationTypeInitializer
{
    public const DEFAULTS = ['Medici specialisti', 'Professionisti sanitari', 'Collaborazioni', 'Area organizzativa', 'Candidatura spontanea'];

    public function initialize(): void
    {
        if (ApplicationType::query()->exists()) {
            return;
        }

        foreach (self::DEFAULTS as $sortOrder => $name) {
            ApplicationType::query()->create(['name' => $name, 'is_active' => true, 'sort_order' => $sortOrder]);
        }
    }
}
