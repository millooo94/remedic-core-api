<?php

use App\Support\Professionals\ProfessionalAreaOptions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('professional_service_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('professional_id')->constrained('professionals')->cascadeOnDelete();
            $table->foreignId('service_category_id')->constrained('service_categories')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['professional_id', 'service_category_id'], 'professional_category_unique');
        });

        $now = now();
        $categoryIdsBySlug = DB::table('service_categories')
            ->pluck('id', 'slug')
            ->mapWithKeys(fn ($id, $slug) => [(string) $slug => (int) $id])
            ->all();

        DB::table('professionals')
            ->select(['id', 'area_name'])
            ->orderBy('id')
            ->chunkById(200, function ($professionals) use (&$categoryIdsBySlug, $now): void {
                foreach ($professionals as $professional) {
                    $normalizedArea = ProfessionalAreaOptions::normalize((string) ($professional->area_name ?? null));
                    if ($normalizedArea === null || $normalizedArea === '') {
                        continue;
                    }

                    $slug = Str::slug($normalizedArea);
                    $categoryId = $categoryIdsBySlug[$slug] ?? null;

                    if ($categoryId === null) {
                        $categoryId = (int) DB::table('service_categories')->insertGetId([
                            'name' => $normalizedArea,
                            'slug' => $slug,
                            'is_active' => true,
                            'sort_order' => null,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                        $categoryIdsBySlug[$slug] = $categoryId;
                    }

                    DB::table('professionals')
                        ->where('id', $professional->id)
                        ->update(['area_name' => $normalizedArea, 'updated_at' => $now]);

                    DB::table('professional_service_categories')->updateOrInsert(
                        [
                            'professional_id' => (int) $professional->id,
                            'service_category_id' => $categoryId,
                        ],
                        [
                            'updated_at' => $now,
                            'created_at' => $now,
                        ],
                    );
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('professional_service_categories');
    }
};

