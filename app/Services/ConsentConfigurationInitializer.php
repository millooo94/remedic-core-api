<?php

namespace App\Services;

use App\Models\ConsentConfiguration;

class ConsentConfigurationInitializer
{
    public function initialize(): ConsentConfiguration
    {
        return ConsentConfiguration::query()->firstOrCreate(
            ['id' => 1],
            ['is_enabled' => false, 'configuration_version' => 1],
        );
    }
}
