<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_settings', function (Blueprint $table): void {
            $table->string('tax_code', 32)->nullable()->after('vat_number');
            $table->string('pec_email')->nullable()->after('clinic_email');
            $table->string('clinic_street_name')->nullable()->after('clinic_address');
            $table->string('clinic_street_number', 32)->nullable()->after('clinic_street_name');
            $table->string('clinic_province', 100)->nullable()->after('clinic_city');
            $table->string('clinic_country_name', 100)->nullable()->after('clinic_country');
            $table->string('google_place_id')->nullable()->after('clinic_country_name');
            $table->string('timezone', 64)->default('Europe/Rome')->after('opening_hours');
            // Canonical factual service territory; province_or_area_served remains a legacy alias.
            $table->string('served_territory')->nullable()->after('province_or_area_served');
            $table->text('google_review_url')->nullable()->change();
            $table->text('logo_path')->nullable()->change();
            $table->text('default_og_image_path')->nullable()->change();
        });

        DB::table('site_settings')->orderBy('id')->get()->each(function (object $row): void {
            $updates = [];
            if (blank($row->clinic_name) && filled($row->brand_name ?? null)) {
                $updates['clinic_name'] = $row->brand_name;
            } elseif (blank($row->clinic_name) && filled($row->site_name ?? null)) {
                $updates['clinic_name'] = $row->site_name;
            }
            if (blank($row->google_maps_url) && filled($row->maps_url ?? null)) {
                $updates['google_maps_url'] = $row->maps_url;
            }
            if (blank($row->served_territory) && filled($row->province_or_area_served ?? null)) {
                $updates['served_territory'] = $row->province_or_area_served;
            }
            $country = strtoupper(trim((string) ($row->clinic_country ?? '')));
            $knownCountries = ['ITALIA' => 'IT', 'ITALY' => 'IT'];
            if (isset($knownCountries[$country])) {
                $updates['clinic_country'] = $knownCountries[$country];
            }
            if ($updates !== []) {
                DB::table('site_settings')->where('id', $row->id)->update($updates);
            }
        });

        DB::table('site_settings')->insertOrIgnore([
            'id' => 1,
            'clinic_country' => 'IT',
            'timezone' => 'Europe/Rome',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('site_settings', function (Blueprint $table): void {
            $table->string('google_review_url')->nullable()->change();
            $table->string('logo_path')->nullable()->change();
            $table->string('default_og_image_path')->nullable()->change();
            $table->dropColumn([
                'tax_code', 'pec_email', 'clinic_street_name', 'clinic_street_number',
                'clinic_province', 'clinic_country_name', 'google_place_id', 'timezone',
                'served_territory',
            ]);
        });
    }
};
