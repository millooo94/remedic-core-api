<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('application_types', function (Blueprint $table): void {
            $table->uuid('public_id')->nullable()->unique()->after('id');
            $table->string('key', 100)->nullable()->unique()->after('name');
        });
        Schema::table('job_applications', function (Blueprint $table): void {
            $table->uuid('public_id')->nullable()->unique()->after('id');
            $table->string('application_type_key_snapshot', 100)->nullable()->after('application_type_name_snapshot');
            $table->string('locale', 5)->default('it')->after('message');
            $table->timestamp('privacy_consent_at')->nullable()->after('locale');
            $table->string('privacy_policy_version', 100)->nullable()->after('privacy_consent_at');
            $table->string('cv_mime_type', 100)->nullable()->after('cv_original_name');
            $table->unsignedBigInteger('cv_size_bytes')->nullable()->after('cv_mime_type');
            $table->timestamp('first_opened_at')->nullable()->after('status');
            $table->foreignId('first_opened_by_user_id')->nullable()->after('first_opened_at')->constrained('users')->nullOnDelete();
            $table->index(['first_opened_at', 'submitted_at'], 'job_applications_unopened_submitted_idx');
        });
        Schema::table('application_settings', function (Blueprint $table): void {
            $table->string('career_recipient_email')->nullable()->after('reminder_email');
        });

        DB::table('application_types')->orderBy('id')->eachById(function (object $type): void {
            $baseKey = Str::slug((string) $type->name, '_') ?: 'application_type';
            $key = $type->key ?: $baseKey;
            $suffix = 2;
            while (DB::table('application_types')->where('key', $key)->where('id', '!=', $type->id)->exists()) {
                $key = $baseKey.'_'.$suffix++;
            }

            DB::table('application_types')->where('id', $type->id)->update([
                'public_id' => $type->public_id ?: (string) Str::uuid(),
                'key' => $key,
            ]);
        });
        DB::table('job_applications')->orderBy('id')->eachById(function (object $application): void {
            DB::table('job_applications')->where('id', $application->id)->update(['public_id' => $application->public_id ?: (string) Str::uuid()]);
        });
        DB::table('job_applications')->whereIn('status', ['contacted', 'closed'])->update(['status' => 'archived']);
    }

    public function down(): void
    {
        Schema::table('application_settings', function (Blueprint $table): void {
            $table->dropColumn('career_recipient_email');
        });
        Schema::table('job_applications', function (Blueprint $table): void {
            $table->dropForeign(['first_opened_by_user_id']);
            $table->dropIndex('job_applications_unopened_submitted_idx');
            $table->dropUnique(['public_id']);
            $table->dropColumns(['public_id', 'application_type_key_snapshot', 'locale', 'privacy_consent_at', 'privacy_policy_version', 'cv_mime_type', 'cv_size_bytes', 'first_opened_at', 'first_opened_by_user_id']);
        });
        Schema::table('application_types', function (Blueprint $table): void {
            $table->dropUnique(['public_id']);
            $table->dropUnique(['key']);
            $table->dropColumns(['public_id', 'key']);
        });
    }
};
