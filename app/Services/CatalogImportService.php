<?php

namespace App\Services;

use App\Models\Professional;
use App\Models\ProfessionalService;
use App\Models\Service;
use App\Models\ServiceAlias;
use App\Models\ServiceCategory;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CatalogImportService
{
    public function import(array $rows, string $sourceLabel = 'seed'): array
    {
        return DB::transaction(function () use ($rows, $sourceLabel): array {
            $report = [
                'source_records' => count($rows),
                'services_created' => 0,
                'aliases_created' => 0,
                'professional_services_created' => 0,
                'records_without_price' => 0,
                'unresolved_records' => [],
            ];

            $professionals = Professional::query()
                ->get()
                ->keyBy(fn (Professional $professional) => $this->normalizePersonKey($professional->full_name));

            foreach ($rows as $index => $row) {
                $professional = $professionals->get($this->normalizePersonKey((string) Arr::get($row, 'professional')));

                if (! $professional) {
                    $report['unresolved_records'][] = [
                        'index' => $index,
                        'reason' => 'Professionista non riconosciuto',
                        'row' => $row,
                    ];
                    continue;
                }

                $categoryName = $this->normalizeAreaName((string) Arr::get($row, 'area', $professional->area_name));
                $category = ServiceCategory::query()->firstOrCreate(
                    ['slug' => Str::slug($categoryName)],
                    [
                        'name' => $categoryName,
                        'is_active' => true,
                    ],
                );

                $rawServiceName = (string) Arr::get($row, 'service_name');
                $canonicalName = $this->normalizeServiceName($rawServiceName);
                $serviceSlug = Str::slug($categoryName.' '.$canonicalName);

                $service = Service::query()->firstOrCreate(
                    ['slug' => $serviceSlug],
                    [
                        'category_id' => $category->id,
                        'canonical_name' => $canonicalName,
                        'display_name' => $canonicalName,
                        'description' => Arr::get($row, 'description'),
                        'default_duration_minutes' => Arr::get($row, 'duration_minutes'),
                        'is_active' => true,
                        'notes' => Arr::get($row, 'notes'),
                    ],
                );

                if ($service->wasRecentlyCreated) {
                    $report['services_created']++;
                }

                if (strcasecmp(trim($rawServiceName), $canonicalName) !== 0) {
                    $alias = ServiceAlias::query()->firstOrCreate(
                        [
                            'service_id' => $service->id,
                            'alias_slug' => Str::slug($this->normalizeWhitespace($rawServiceName)),
                        ],
                        [
                            'alias_name' => $this->normalizeWhitespace($rawServiceName),
                            'source_label' => $sourceLabel,
                        ],
                    );

                    if ($alias->wasRecentlyCreated) {
                        $report['aliases_created']++;
                    }
                }

                $priceAmount = $this->resolvePriceAmount((string) Arr::get($row, 'price'));

                $link = ProfessionalService::query()->updateOrCreate(
                    [
                        'professional_id' => $professional->id,
                        'service_id' => $service->id,
                    ],
                    [
                        'duration_minutes' => Arr::get($row, 'duration_minutes'),
                        'price_amount' => $priceAmount,
                        'is_visible_public' => Arr::get($row, 'is_visible_public', true),
                        'is_bookable_online' => Arr::get($row, 'is_bookable_online', false),
                        'source_platform' => Arr::get($row, 'source_platform', 'catalogo_interno'),
                        'source_notes' => Arr::get($row, 'source_notes'),
                        'is_active' => Arr::get($row, 'is_active', true),
                    ],
                );

                if ($link->wasRecentlyCreated) {
                    $report['professional_services_created']++;
                }

                if ($priceAmount === null) {
                    $report['records_without_price']++;
                }
            }

            return $report;
        });
    }

    public function normalizeAreaName(string $areaName): string
    {
        $normalized = Str::of($areaName)
            ->replace(['â€™', '`'], "'")
            ->squish()
            ->lower()
            ->value();

        $map = [
            'neurologo' => 'Neurologia',
            'neurologia' => 'Neurologia',
            'senologo' => 'Senologia',
            'senologia' => 'Senologia',
            'psicologo clinico' => 'Psicologia Clinica',
            'psicologia clinica' => 'Psicologia Clinica',
            'cardiologo' => 'Cardiologia',
            'cardiologia' => 'Cardiologia',
            'ginecologo' => 'Ginecologia',
            'ginecologia' => 'Ginecologia',
            'chirurgo vascolare' => 'Chirurgia Vascolare',
            'chirurgia vascolare' => 'Chirurgia Vascolare',
            'endocrinologo' => 'Endocrinologia',
            'endocrinologia' => 'Endocrinologia',
            'chirurgo plastico' => 'Chirurgia Plastica',
            'chirurgia plastica' => 'Chirurgia Plastica',
            'medico estetico' => 'Medicina Estetica',
            'medicina estetica' => 'Medicina Estetica',
            'urologo' => 'Urologia',
            'urologia' => 'Urologia',
            'nutrizionista' => 'Nutrizione',
            'nutrizione' => 'Nutrizione',
            'dermatologo' => 'Dermatologia',
            'dermatologia' => 'Dermatologia',
            'reumatologo' => 'Reumatologia',
            'reumatologia' => 'Reumatologia',
            'internista' => 'Medicina Interna',
            'medicina interna' => 'Medicina Interna',
            'angiologo' => 'Angiologia',
            'angiologia' => 'Angiologia',
            'ostetricia' => 'Ostetricia',
            'andrologia' => 'Andrologia',
            'terapia del dolore' => 'Terapia del Dolore',
            'chirurgia generale' => 'Chirurgia Generale',
            'maxillo-facciale' => 'Chirurgia maxillo-facciale',
            'chirurgia maxillo-facciale' => 'Chirurgia maxillo-facciale',
            'tecnico sanitario' => 'Tecnico sanitario',
        ];

        return $map[$normalized] ?? Str::title($normalized);
    }

    public function normalizeServiceName(string $serviceName): string
    {
        $normalized = $this->normalizeWhitespace($serviceName);
        $lower = Str::lower($normalized);

        if (preg_match('/(?:laser\\s+(?:depilazione|epilazione)|(?:depilazione|epilazione)\\s+laser)\\s+(.+)$/iu', $lower, $matches) === 1) {
            $normalized = 'Epilazione laser '.$this->sentenceCase($matches[1]);
        }

        $normalized = Str::lower(preg_replace('/\s*\+\s*/u', ' + ', $normalized) ?? $normalized);
        $normalized = Str::ucfirst($normalized);
        $normalized = preg_replace_callback(
            '/\+\s*([a-zÃ -Ã¿][^+]+)/u',
            fn (array $matches) => '+ '.Str::ucfirst(trim($matches[1])),
            $normalized,
        ) ?? $normalized;
        $normalized = str_ireplace('ecg', 'ECG', $normalized);
        $normalized = preg_replace('/pap test/ui', 'Pap test', $normalized) ?? $normalized;
        $normalized = preg_replace('/eco color doppler/ui', 'Ecocolordoppler', $normalized) ?? $normalized;

        return trim($normalized);
    }

    private function resolvePriceAmount(string $price): ?string
    {
        $clean = Str::lower(trim(str_replace(['â‚¬', '€', 'euro'], '', $price)));
        $clean = str_replace(',', '.', $clean);

        if ($clean === '' || $clean === '-') {
            return null;
        }

        if (preg_match('/^da\s+(\d+(?:\.\d+)?)$/', $clean, $matches) === 1) {
            return number_format((float) $matches[1], 2, '.', '');
        }

        if (preg_match('/^(\d+(?:\.\d+)?)$/', $clean, $matches) === 1) {
            return number_format((float) $matches[1], 2, '.', '');
        }

        return null;
    }

    private function normalizePersonKey(string $fullName): string
    {
        return Str::of($fullName)
            ->replace(['â€™', '`'], "'")
            ->replace(["\u{2019}", "\u{2018}"], "'")
            ->ascii()
            ->lower()
            ->squish()
            ->value();
    }

    private function normalizeWhitespace(string $value): string
    {
        return Str::of($value)
            ->replace(['â€™', '`'], "'")
            ->replace(["\u{2019}", "\u{2018}"], "'")
            ->squish()
            ->value();
    }

    private function sentenceCase(string $value): string
    {
        return Str::of($value)->lower()->ucfirst()->value();
    }
}
