<?php

namespace Database\Seeders;

use App\Models\ApplicationSetting;
use Illuminate\Database\Seeder;

class ApplicationSettingSeeder extends Seeder
{
    public function run(): void
    {
        ApplicationSetting::query()->updateOrCreate(
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
}
