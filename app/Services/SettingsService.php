<?php

namespace App\Services;

use App\Models\ApplicationSetting;
use App\Models\User;

class SettingsService
{
    public function get(): ApplicationSetting
    {
        return ApplicationSetting::query()->firstOrCreate(
            ['id' => 1],
            [
                'reminder_email' => 'humancaretelemedicine@gmail.com',
                'quick_percentages' => [70, 60, 50],
                'general_preferences' => [
                    'company_name' => 'Humancare Telemedicine S.r.l.',
                    'default_currency' => 'EUR',
                    'default_calculation_mode' => 'percentage',
                    'default_percentage_value' => 70,
                    'reminder_enabled' => true,
                    'reminder_day_of_month' => 20,
                    'reminder_subject' => 'Promemoria prospetti professionisti Remedic',
                    'reminder_body' => 'Oggi e il giorno previsto per verificare le prestazioni effettuate e preparare i prospetti da inviare ai professionisti.',
                ],
            ],
        );
    }

    public function update(array $payload, User $actor): ApplicationSetting
    {
        $settings = $this->get();
        $settings->fill($payload);
        $settings->updated_by = $actor->id;
        $settings->save();

        return $settings->refresh();
    }
}
