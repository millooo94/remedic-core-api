<?php

namespace Database\Seeders;

use App\Models\Professional;
use App\Models\ServiceCategory;
use App\Models\Specialization;
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
            $normalizedArea = $this->normalizeAreaName($professional['area_name']);

            $professionalModel = Professional::query()->updateOrCreate(
                ['full_name' => trim($professional['last_name'].' '.$professional['first_name'])],
                [
                    ...$professional,
                    'subject_type' => 'individual',
                    'gender' => 'unspecified',
                    'company_name' => null,
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

            $specialization = Specialization::query()->firstOrCreate(
                ['slug' => Str::slug((string) $normalizedArea)],
                [
                    'name' => $normalizedArea,
                    ...$this->professionalTitlesForArea($normalizedArea),
                    'robots' => 'index,follow',
                    'is_local_seo_enabled' => true,
                    'is_active' => true,
                    'sort_order' => 0,
                ],
            );

            $professionalModel->areas()->syncWithoutDetaching([$areaCategory->id]);
            $professionalModel->specializations()->syncWithoutDetaching([
                $specialization->id => [
                    'is_primary' => true,
                    'sort_order' => 0,
                ],
            ]);
        }
    }

    private function normalizeAreaName(string $areaName): string
    {
        $trimmed = trim($areaName);
        $slug = Str::slug($trimmed);

        return Specialization::query()->where('slug', $slug)->value('name')
            ?? ServiceCategory::query()->where('slug', $slug)->value('name')
            ?? $trimmed;
    }

    /**
     * @return array{professional_title_male: string|null, professional_title_female: string|null}
     */
    private function professionalTitlesForArea(string $areaName): array
    {
        return match (Str::lower($areaName)) {
            'angiologia' => ['professional_title_male' => 'angiologo', 'professional_title_female' => 'angiologa'],
            'cardiologia' => ['professional_title_male' => 'cardiologo', 'professional_title_female' => 'cardiologa'],
            'chirurgia plastica' => ['professional_title_male' => 'chirurgo plastico', 'professional_title_female' => 'chirurga plastica'],
            'chirurgia vascolare' => ['professional_title_male' => 'chirurgo vascolare', 'professional_title_female' => 'chirurga vascolare'],
            'dermatologia' => ['professional_title_male' => 'dermatologo', 'professional_title_female' => 'dermatologa'],
            'endocrinologia' => ['professional_title_male' => 'endocrinologo', 'professional_title_female' => 'endocrinologa'],
            'ginecologia' => ['professional_title_male' => 'ginecologo', 'professional_title_female' => 'ginecologa'],
            'medicina estetica' => ['professional_title_male' => 'medico estetico', 'professional_title_female' => 'medica estetica'],
            'medicina interna' => ['professional_title_male' => 'internista', 'professional_title_female' => 'internista'],
            'neurologia' => ['professional_title_male' => 'neurologo', 'professional_title_female' => 'neurologa'],
            'nutrizione' => ['professional_title_male' => 'nutrizionista', 'professional_title_female' => 'nutrizionista'],
            'psicologia clinica' => ['professional_title_male' => 'psicologo clinico', 'professional_title_female' => 'psicologa clinica'],
            'reumatologia' => ['professional_title_male' => 'reumatologo', 'professional_title_female' => 'reumatologa'],
            'urologia' => ['professional_title_male' => 'urologo', 'professional_title_female' => 'urologa'],
            default => ['professional_title_male' => null, 'professional_title_female' => null],
        };
    }
}
