<?php

namespace Database\Seeders;

use App\Models\ExpenseCategory;
use App\Models\ExpenseRecord;
use App\Models\ExpenseTemplate;
use Illuminate\Database\Seeder;

class ExpenseSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            ['category' => 'Affitto', 'name' => 'Affitto sede', 'type' => 'fixed', 'recurrence' => 'monthly', 'default_amount' => 2200],
            ['category' => 'Software', 'name' => 'Software gestionale', 'type' => 'fixed', 'recurrence' => 'monthly', 'default_amount' => 180],
            ['category' => 'Pulizie', 'name' => 'Servizio pulizie', 'type' => 'fixed', 'recurrence' => 'monthly', 'default_amount' => 240],
            ['category' => 'Utenze', 'name' => 'Utenze sede', 'type' => 'fixed', 'recurrence' => 'monthly', 'default_amount' => 310],
        ];

        foreach ($templates as $templateData) {
            $category = ExpenseCategory::query()->where('name', $templateData['category'])->firstOrFail();

            ExpenseTemplate::query()->updateOrCreate(
                ['name' => $templateData['name']],
                [
                    'category_id' => $category->id,
                    'type' => $templateData['type'],
                    'recurrence' => $templateData['recurrence'],
                    'default_amount' => $templateData['default_amount'],
                    'is_active' => true,
                ],
            );
        }

        $records = [
            ['date' => '2026-02-05', 'category' => 'Affitto', 'type' => 'fixed', 'description' => 'Canone mensile sede', 'amount' => 2200, 'supplier' => 'Locatore'],
            ['date' => '2026-02-07', 'category' => 'Software', 'type' => 'fixed', 'description' => 'Licenze software', 'amount' => 180, 'supplier' => 'Software Srl'],
            ['date' => '2026-02-18', 'category' => 'Marketing', 'type' => 'variable', 'description' => 'Campagna Meta', 'amount' => 420, 'supplier' => 'Meta'],
            ['date' => '2026-03-05', 'category' => 'Affitto', 'type' => 'fixed', 'description' => 'Canone mensile sede', 'amount' => 2200, 'supplier' => 'Locatore'],
            ['date' => '2026-03-11', 'category' => 'Materiali sanitari', 'type' => 'variable', 'description' => 'Materiali ambulatoriali', 'amount' => 260, 'supplier' => 'Fornitore Med'],
            ['date' => '2026-03-22', 'category' => 'Pulizie', 'type' => 'fixed', 'description' => 'Pulizie mensili', 'amount' => 240, 'supplier' => 'Clean Service'],
            ['date' => '2026-04-05', 'category' => 'Affitto', 'type' => 'fixed', 'description' => 'Canone mensile sede', 'amount' => 2200, 'supplier' => 'Locatore'],
            ['date' => '2026-04-09', 'category' => 'Consulenze', 'type' => 'variable', 'description' => 'Consulenza fiscale', 'amount' => 350, 'supplier' => 'Studio Commerciale'],
            ['date' => '2026-05-03', 'category' => 'Affitto', 'type' => 'fixed', 'description' => 'Canone mensile sede', 'amount' => 2200, 'supplier' => 'Locatore'],
            ['date' => '2026-05-12', 'category' => 'Marketing', 'type' => 'variable', 'description' => 'Campagna Google', 'amount' => 310, 'supplier' => 'Google'],
            ['date' => '2026-06-05', 'category' => 'Affitto', 'type' => 'fixed', 'description' => 'Canone mensile sede', 'amount' => 2200, 'supplier' => 'Locatore'],
            ['date' => '2026-06-15', 'category' => 'Manutenzione', 'type' => 'variable', 'description' => 'Manutenzione climatizzatore', 'amount' => 290, 'supplier' => 'Tecnoclima'],
        ];

        foreach ($records as $record) {
            $category = ExpenseCategory::query()->where('name', $record['category'])->firstOrFail();

            ExpenseRecord::query()->updateOrCreate(
                [
                    'expense_date' => $record['date'],
                    'description' => $record['description'],
                ],
                [
                    'expense_category_id' => $category->id,
                    'competence_month' => (int) date('n', strtotime($record['date'])),
                    'competence_year' => (int) date('Y', strtotime($record['date'])),
                    'type' => $record['type'],
                    'amount' => $record['amount'],
                    'supplier' => $record['supplier'],
                    'payment_status' => 'pagata',
                ],
            );
        }
    }
}
