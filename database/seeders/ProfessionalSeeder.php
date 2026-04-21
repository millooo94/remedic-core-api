<?php

namespace Database\Seeders;

use App\Models\Professional;
use App\Models\ServiceCategory;
use App\Support\Professionals\ProfessionalAreaOptions;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ProfessionalSeeder extends Seeder
{
    public function run(): void
    {
        $professionals = [
            ['first_name' => 'Sebastiano', 'last_name' => 'Arena', 'area_name' => 'Neurologia', 'is_active' => true],
            ['first_name' => 'Martino', 'last_name' => 'Barbara', 'area_name' => 'Senologia', 'is_active' => true],
            ['first_name' => 'Jessica', 'last_name' => 'Belfiore', 'area_name' => 'Psicologia Clinica', 'is_active' => true],
            ['first_name' => 'Giuseppe', 'last_name' => 'Bottaro', 'area_name' => 'Cardiologia', 'is_active' => true],
            ['first_name' => 'Saveria Maria', 'last_name' => 'Cantone', 'area_name' => 'Ginecologia', 'is_active' => true],
            ['first_name' => 'Matteo', 'last_name' => 'Cavallo', 'area_name' => 'Chirurgia Vascolare', 'is_active' => true],
            ['first_name' => 'Andrea', 'last_name' => 'Crafa', 'area_name' => 'Endocrinologia', 'is_active' => true],
            ['first_name' => 'Giovanni', 'last_name' => "D'Agosta", 'area_name' => 'Chirurgia Plastica', 'is_active' => true],
            ['first_name' => 'Bruna', 'last_name' => "D'Amico", 'area_name' => 'Medicina Estetica', 'is_active' => false],
            ['first_name' => 'Agata', 'last_name' => 'Di Dio', 'area_name' => 'Urologia', 'is_active' => true],
            ['first_name' => 'Giacomo', 'last_name' => 'Di Mulo', 'area_name' => 'Nutrizione', 'is_active' => true],
            ['first_name' => 'Antonino', 'last_name' => 'Di Salvo', 'area_name' => 'Dermatologia', 'is_active' => true],
            ['first_name' => 'Rosario Emanuele', 'last_name' => 'Distefano', 'area_name' => 'Ginecologia', 'is_active' => true],
            ['first_name' => 'Livia', 'last_name' => 'Liguori', 'area_name' => 'Dermatologia', 'is_active' => true],
            ['first_name' => 'Vincenzo', 'last_name' => 'Maccarrone', 'area_name' => 'Reumatologia', 'is_active' => true],
            ['first_name' => 'Claudia', 'last_name' => 'Maugeri', 'area_name' => 'Medicina Estetica', 'is_active' => true],
            ['first_name' => 'Giorgia', 'last_name' => 'Patanè', 'area_name' => 'Tecnico sanitario', 'is_active' => true],
            ['first_name' => 'Giovanni Mario', 'last_name' => 'Rapisarda', 'area_name' => 'Medicina Interna', 'is_active' => true],
            ['first_name' => 'Ilenia', 'last_name' => 'Russo', 'area_name' => 'Nutrizione', 'is_active' => true],
            ['first_name' => 'Rosario', 'last_name' => 'Scuderi', 'area_name' => 'Angiologia', 'is_active' => true],
            ['first_name' => 'Simona', 'last_name' => 'Zappalà', 'area_name' => 'Medicina Estetica', 'is_active' => true],
        ];

        foreach ($professionals as $professional) {
            $normalizedArea = ProfessionalAreaOptions::normalize($professional['area_name']);

            $professionalModel = Professional::query()->updateOrCreate(
                ['full_name' => trim($professional['last_name'].' '.$professional['first_name'])],
                [
                    ...$professional,
                    'full_name' => trim($professional['last_name'].' '.$professional['first_name']),
                    'area_name' => $normalizedArea,
                    'email' => null,
                    'iban' => null,
                    'notes' => null,
                ],
            );

            $areaCategory = ServiceCategory::query()->firstOrCreate(
                ['slug' => Str::slug((string) $normalizedArea)],
                ['name' => $normalizedArea, 'is_active' => true],
            );

            $professionalModel->areas()->syncWithoutDetaching([$areaCategory->id]);
        }
    }
}
