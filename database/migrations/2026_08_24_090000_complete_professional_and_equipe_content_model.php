<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('professionals', function (Blueprint $table): void {
            $table->string('honorific_prefix')->nullable()->after('gender');
            $table->date('birth_date')->nullable()->after('full_name');
            $table->string('birth_place')->nullable()->after('birth_date');
        });

        Schema::table('professional_board_registrations', function (Blueprint $table): void {
            $table->string('registration_number')->nullable()->after('board_name');
        });

        Schema::table('professional_public_profiles', function (Blueprint $table): void {
            $table->longText('bio_content')->nullable()->after('short_bio');
            $table->longText('approach_content')->nullable()->after('bio_content');
            $table->boolean('is_web_enabled')->default(false)->index()->after('is_active');
        });

        Schema::create('professional_career_experiences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('professional_id')->constrained('professionals')->cascadeOnDelete();
            $table->unsignedSmallInteger('year_from');
            $table->unsignedSmallInteger('year_to')->nullable();
            $table->boolean('is_current')->default(false);
            $table->string('title');
            $table->string('organization')->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->timestamps();
        });

        Schema::create('professional_profile_approach_principles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('professional_public_profile_id');
            $table->foreign('professional_public_profile_id', 'ppap_profile_fk')
                ->references('id')->on('professional_public_profiles')->cascadeOnDelete();
            $table->string('label');
            $table->string('icon_key')->nullable();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('professional_profile_competencies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('professional_public_profile_id');
            $table->foreign('professional_public_profile_id', 'ppc_profile_fk')
                ->references('id')->on('professional_public_profiles')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('icon_key')->nullable();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('professional_profile_hero_competencies', function (Blueprint $table): void {
            $table->foreignId('professional_public_profile_id');
            $table->foreign('professional_public_profile_id', 'pphc_profile_fk')
                ->references('id')->on('professional_public_profiles')->cascadeOnDelete();
            $table->foreignId('professional_profile_competency_id');
            $table->foreign('professional_profile_competency_id', 'pphc_competency_fk')
                ->references('id')->on('professional_profile_competencies')->cascadeOnDelete();
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->primary(
                ['professional_public_profile_id', 'professional_profile_competency_id'],
                'pphc_primary'
            );
        });

        Schema::create('professional_profile_scientific_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('professional_public_profile_id');
            $table->foreign('professional_public_profile_id', 'ppsa_profile_fk')
                ->references('id')->on('professional_public_profiles')->cascadeOnDelete();
            $table->string('contribution_type');
            $table->date('occurred_on')->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('title');
            $table->string('source')->nullable();
            $table->text('short_description')->nullable();
            $table->string('url')->nullable();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        DB::table('professional_public_profiles')
            ->orderBy('id')
            ->each(function (object $profile): void {
                $masterUpdates = [];
                if (trim((string) $profile->title_prefix) !== '') {
                    $masterUpdates['honorific_prefix'] = trim((string) $profile->title_prefix);
                }
                if ($profile->birth_date !== null) {
                    $masterUpdates['birth_date'] = $profile->birth_date;
                }
                if (trim((string) $profile->birth_place) !== '') {
                    $masterUpdates['birth_place'] = trim((string) $profile->birth_place);
                }

                foreach ($masterUpdates as $column => $value) {
                    DB::table('professionals')
                        ->where('id', $profile->professional_id)
                        ->whereNull($column)
                        ->update([$column => $value]);
                }

                DB::table('professional_public_profiles')
                    ->where('id', $profile->id)
                    ->update(['is_web_enabled' => (bool) $profile->is_active]);

                $registrationNumber = trim((string) $profile->registration_number);
                if ($registrationNumber !== '') {
                    $registrations = DB::table('professional_board_registrations')
                        ->where('professional_id', $profile->professional_id)
                        ->get();

                    if ($registrations->count() === 1 && trim((string) $registrations->first()->registration_number) === '') {
                        DB::table('professional_board_registrations')
                            ->where('id', $registrations->first()->id)
                            ->update(['registration_number' => $registrationNumber]);
                    }
                }

                $sectionDefinitions = [
                    'hero' => 'Hero / Profilo professionale',
                    'biography' => 'Biografia',
                    'approach' => 'Il mio approccio',
                    'competencies' => 'Competenze cliniche',
                    'career' => 'Percorso professionale',
                    'scientific_activity' => 'Attività scientifica',
                ];
                foreach (array_keys($sectionDefinitions) as $order => $key) {
                    $exists = DB::table('sections')
                        ->where('sectionable_type', 'App\\Models\\ProfessionalPublicProfile')
                        ->where('sectionable_id', $profile->id)
                        ->where('key', $key)
                        ->exists();
                    if (! $exists) {
                        DB::table('sections')->insert([
                            'sectionable_type' => 'App\\Models\\ProfessionalPublicProfile',
                            'sectionable_id' => $profile->id,
                            'key' => $key,
                            'title' => $sectionDefinitions[$key],
                            'sort_order' => $order,
                            'is_active' => true,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('professional_profile_scientific_activities');
        Schema::dropIfExists('professional_profile_hero_competencies');
        Schema::dropIfExists('professional_profile_competencies');
        Schema::dropIfExists('professional_profile_approach_principles');
        Schema::dropIfExists('professional_career_experiences');

        Schema::table('professional_public_profiles', function (Blueprint $table): void {
            $table->dropColumn(['bio_content', 'approach_content', 'is_web_enabled']);
        });

        Schema::table('professional_board_registrations', function (Blueprint $table): void {
            $table->dropColumn('registration_number');
        });

        Schema::table('professionals', function (Blueprint $table): void {
            $table->dropColumn(['honorific_prefix', 'birth_date', 'birth_place']);
        });
    }
};
