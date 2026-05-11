<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $seedNames = [
            'Angiologia',
            'Allergologia',
            'Analisi cliniche',
            'Cardiologia',
            'Chirurgia maxillo-facciale',
            'Chirurgia plastica',
            'Chirurgia vascolare',
            'Dermatologia',
            'Dietologia',
            'Ecografia',
            'Endocrinologia',
            'Ginecologia',
            'Ostetricia',
            'Medicina estetica',
            'Medicina interna',
            'Neurologia',
            'Nutrizione',
            'Pneumologia',
            'Psicologia clinica',
            'Reumatologia',
            'Senologia',
            'Tecnico sanitario',
            'Urologia',
        ];

        $discoveredNames = DB::table('service_categories')
            ->pluck('name')
            ->merge(DB::table('professionals')->pluck('area_name'))
            ->filter(fn ($value) => is_string($value) && trim($value) !== '')
            ->map(fn ($value) => trim((string) $value))
            ->values()
            ->all();

        $allNames = collect([...$seedNames, ...$discoveredNames])
            ->map(fn (string $name) => trim($name))
            ->filter()
            ->unique(fn (string $name) => Str::slug($name))
            ->values();

        $specializationIdsBySlug = [];

        foreach ($allNames as $index => $name) {
            $slug = Str::slug($name);
            $existing = DB::table('specializations')->where('slug', $slug)->first();

            if ($existing) {
                DB::table('specializations')
                    ->where('id', $existing->id)
                    ->update([
                        'name' => $existing->name ?: $name,
                        'sort_order' => $existing->sort_order ?? $index,
                        'updated_at' => $now,
                    ]);

                $specializationIdsBySlug[$slug] = (int) $existing->id;
            } else {
                $specializationIdsBySlug[$slug] = (int) DB::table('specializations')->insertGetId([
                    'name' => $name,
                    'slug' => $slug,
                    'robots' => 'index,follow',
                    'is_local_seo_enabled' => true,
                    'is_active' => true,
                    'sort_order' => $index,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            DB::table('service_categories')->updateOrInsert(
                ['slug' => $slug],
                [
                    'name' => $name,
                    'is_active' => true,
                    'sort_order' => $index,
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );
        }

        $serviceCategoryNamesById = DB::table('service_categories')->pluck('name', 'id');

        DB::table('professionals')
            ->select(['id', 'area_name'])
            ->orderBy('id')
            ->chunkById(200, function ($professionals) use ($serviceCategoryNamesById, $specializationIdsBySlug, $now): void {
                foreach ($professionals as $professional) {
                    $names = DB::table('professional_service_categories')
                        ->join('service_categories', 'service_categories.id', '=', 'professional_service_categories.service_category_id')
                        ->where('professional_service_categories.professional_id', $professional->id)
                        ->orderBy('professional_service_categories.sort_order')
                        ->orderBy('professional_service_categories.id')
                        ->pluck('service_categories.name')
                        ->map(fn ($name) => trim((string) $name))
                        ->filter()
                        ->values();

                    if ($names->isEmpty() && is_string($professional->area_name) && trim($professional->area_name) !== '') {
                        $names->push(trim((string) $professional->area_name));
                    }

                    $names = $names
                        ->unique(fn (string $name) => Str::slug($name))
                        ->values();

                    foreach ($names as $index => $name) {
                        $specializationId = $specializationIdsBySlug[Str::slug($name)] ?? null;
                        if (! $specializationId) {
                            continue;
                        }

                        DB::table('professional_specialization')->updateOrInsert(
                            [
                                'professional_id' => (int) $professional->id,
                                'specialization_id' => $specializationId,
                            ],
                            [
                                'is_primary' => $index === 0,
                                'sort_order' => $index,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ],
                        );
                    }

                    if ($names->isNotEmpty()) {
                        DB::table('professionals')
                            ->where('id', $professional->id)
                            ->update([
                                'area_name' => $names->first(),
                                'updated_at' => $now,
                            ]);
                    }
                }
            });

        DB::table('services')
            ->select(['id', 'category_id'])
            ->orderBy('id')
            ->chunkById(200, function ($services) use ($serviceCategoryNamesById, $specializationIdsBySlug, $now): void {
                foreach ($services as $service) {
                    $categoryName = $service->category_id ? trim((string) ($serviceCategoryNamesById[$service->category_id] ?? '')) : '';
                    if ($categoryName === '') {
                        continue;
                    }

                    $specializationId = $specializationIdsBySlug[Str::slug($categoryName)] ?? null;
                    if (! $specializationId) {
                        continue;
                    }

                    DB::table('service_specialization')->updateOrInsert(
                        [
                            'service_id' => (int) $service->id,
                            'specialization_id' => $specializationId,
                        ],
                        [
                            'is_primary' => true,
                            'sort_order' => 0,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ],
                    );
                }
            });
    }

    public function down(): void
    {
    }
};
