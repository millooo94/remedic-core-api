<?php

namespace Tests\Feature;

use App\Models\ExpenseRecord;
use App\Models\ExpenseRecordCompetence;
use App\Models\OldCoreImportMapping;
use App\Models\Patient;
use App\Services\OldCoreDataImportService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OldCoreImportCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_runs_the_dry_run_even_when_the_source_connection_is_not_available(): void
    {
        config()->set('database.connections.old_core.database', null);
        DB::purge('old_core');

        $this->artisan('merge:import-core-data', ['--dry-run' => true])
            ->assertExitCode(0);
    }

    #[Test]
    public function it_imports_patients_idempotently_with_a_dedicated_mapping_table(): void
    {
        $sourceDatabase = tempnam(sys_get_temp_dir(), 'old-core-source-');

        config()->set('database.connections.old_core', [
            'driver' => 'sqlite',
            'database' => $sourceDatabase,
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);

        DB::purge('old_core');

        $schema = Schema::connection('old_core');
        $schema->create('patients', function (Blueprint $table): void {
            $table->id();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('full_name');
            $table->string('tax_code', 16)->nullable();
            $table->string('sex', 20)->nullable();
            $table->date('birth_date')->nullable();
            $table->unsignedSmallInteger('year_of_birth')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('residence_address')->nullable();
            $table->string('residence_city')->nullable();
            $table->string('residence_zip')->nullable();
            $table->decimal('residence_latitude', 10, 7)->nullable();
            $table->decimal('residence_longitude', 10, 7)->nullable();
            $table->string('geocoding_status', 20)->nullable();
            $table->timestamp('geocoded_at')->nullable();
            $table->boolean('contactable_sms')->default(true);
            $table->boolean('contactable_email')->default(true);
            $table->boolean('excluded_from_campaigns')->default(false);
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        DB::connection('old_core')->table('patients')->insert([
            'id' => 101,
            'first_name' => 'Mario',
            'last_name' => 'Rossi',
            'full_name' => 'Mario Rossi',
            'tax_code' => 'RSSMRA80A01H501Z',
            'sex' => 'M',
            'birth_date' => '1980-01-01',
            'year_of_birth' => 1980,
            'phone' => '+39 333 111 2222',
            'email' => 'mario.rossi@example.test',
            'residence_address' => 'Via Roma 1',
            'residence_city' => 'Acireale',
            'residence_zip' => '95024',
            'residence_latitude' => null,
            'residence_longitude' => null,
            'geocoding_status' => null,
            'geocoded_at' => null,
            'contactable_sms' => true,
            'contactable_email' => true,
            'excluded_from_campaigns' => false,
            'notes' => 'Import test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = app(OldCoreDataImportService::class);

        $firstReport = $service->import(['patients'], false);
        $secondReport = $service->import(['patients'], false);

        $this->assertSame(1, $firstReport['items']['patients']['created']);
        $this->assertSame(1, $secondReport['items']['patients']['updated']);
        $this->assertSame(1, Patient::query()->count());
        $this->assertSame(1, OldCoreImportMapping::query()->where('entity_type', 'patient')->count());

        $patient = Patient::query()->first();
        $this->assertNotNull($patient);
        $this->assertSame('Mario', $patient->first_name);
        $this->assertSame('Rossi', $patient->last_name);

        DB::disconnect('old_core');

        if ($sourceDatabase !== false && is_file($sourceDatabase)) {
            @unlink($sourceDatabase);
        }
    }

    #[Test]
    public function rebuild_from_source_replaces_current_patients_and_preserves_duplicate_rows_from_the_source(): void
    {
        $sourceDatabase = tempnam(sys_get_temp_dir(), 'old-core-rebuild-');

        config()->set('database.connections.old_core', [
            'driver' => 'sqlite',
            'database' => $sourceDatabase,
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);

        DB::purge('old_core');

        $schema = Schema::connection('old_core');
        $schema->create('patients', function (Blueprint $table): void {
            $table->id();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('full_name');
            $table->string('tax_code', 16)->nullable();
            $table->string('sex', 20)->nullable();
            $table->date('birth_date')->nullable();
            $table->unsignedSmallInteger('year_of_birth')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('residence_address')->nullable();
            $table->string('residence_city')->nullable();
            $table->string('residence_zip')->nullable();
            $table->decimal('residence_latitude', 10, 7)->nullable();
            $table->decimal('residence_longitude', 10, 7)->nullable();
            $table->string('geocoding_status', 20)->nullable();
            $table->timestamp('geocoded_at')->nullable();
            $table->boolean('contactable_sms')->default(true);
            $table->boolean('contactable_email')->default(true);
            $table->boolean('excluded_from_campaigns')->default(false);
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        DB::connection('old_core')->table('patients')->insert([
            [
                'id' => 201,
                'first_name' => 'Giulia',
                'last_name' => 'Bianchi',
                'full_name' => 'Giulia Bianchi',
                'tax_code' => 'BNCGLL90A41F205Y',
                'sex' => 'F',
                'birth_date' => '1990-01-01',
                'year_of_birth' => 1990,
                'phone' => '3331112222',
                'email' => 'giulia@example.test',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 202,
                'first_name' => 'Giulia',
                'last_name' => 'Bianchi',
                'full_name' => 'Giulia Bianchi',
                'tax_code' => 'BNCGLL90A41F205Y',
                'sex' => 'F',
                'birth_date' => '1990-01-01',
                'year_of_birth' => 1990,
                'phone' => '3331112222',
                'email' => 'giulia.duplicata@example.test',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        Patient::query()->create([
            'first_name' => 'Record',
            'last_name' => 'Preesistente',
            'full_name' => 'Record Preesistente',
        ]);

        $this->artisan('old-core:rebuild-from-source', ['--force' => true])
            ->assertExitCode(0);

        $this->assertSame(2, Patient::query()->count());
        $this->assertSame(2, OldCoreImportMapping::query()->where('entity_type', 'patient')->count());
        $this->assertSame(
            ['giulia@example.test', 'giulia.duplicata@example.test'],
            Patient::query()->orderBy('id')->pluck('email')->all(),
        );

        DB::disconnect('old_core');

        if ($sourceDatabase !== false && is_file($sourceDatabase)) {
            @unlink($sourceDatabase);
        }
    }

    #[Test]
    public function rebuild_from_source_does_not_duplicate_expense_competences_generated_by_the_observer(): void
    {
        $sourceDatabase = tempnam(sys_get_temp_dir(), 'old-core-expense-');

        config()->set('database.connections.old_core', [
            'driver' => 'sqlite',
            'database' => $sourceDatabase,
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);

        DB::purge('old_core');

        $schema = Schema::connection('old_core');
        $schema->create('expense_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug');
            $table->boolean('is_active')->default(true);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        $schema->create('expense_records', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('expense_category_id');
            $table->unsignedBigInteger('expense_template_id')->nullable();
            $table->unsignedBigInteger('source_performance_record_id')->nullable();
            $table->string('source')->default('manual');
            $table->string('generation_key')->nullable();
            $table->date('expense_date');
            $table->date('competence_start_date')->nullable();
            $table->date('competence_end_date')->nullable();
            $table->unsignedSmallInteger('competence_months_count')->default(1);
            $table->unsignedTinyInteger('competence_month');
            $table->unsignedSmallInteger('competence_year');
            $table->string('description');
            $table->string('type');
            $table->decimal('amount', 12, 2);
            $table->string('supplier')->nullable();
            $table->string('payment_status')->default('pagata');
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        $schema->create('expense_record_competences', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('expense_record_id');
            $table->date('competence_date');
            $table->unsignedTinyInteger('competence_month');
            $table->unsignedSmallInteger('competence_year');
            $table->decimal('allocated_amount', 12, 2);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        DB::connection('old_core')->table('expense_categories')->insert([
            'id' => 1,
            'name' => 'Fornitori',
            'slug' => 'fornitori',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::connection('old_core')->table('expense_records')->insert([
            'id' => 10,
            'expense_category_id' => 1,
            'expense_template_id' => null,
            'source_performance_record_id' => null,
            'source' => 'manual',
            'generation_key' => null,
            'expense_date' => '2026-02-10',
            'competence_start_date' => '2026-02-01',
            'competence_end_date' => '2026-02-01',
            'competence_months_count' => 1,
            'competence_month' => 2,
            'competence_year' => 2026,
            'description' => 'Costo test',
            'type' => 'variable',
            'amount' => 132.00,
            'supplier' => 'Supplier test',
            'payment_status' => 'pagata',
            'notes' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::connection('old_core')->table('expense_record_competences')->insert([
            'id' => 50,
            'expense_record_id' => 10,
            'competence_date' => '2026-02-01',
            'competence_month' => 2,
            'competence_year' => 2026,
            'allocated_amount' => 132.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('old-core:rebuild-from-source', ['--force' => true])
            ->assertExitCode(0);

        $this->assertSame(1, ExpenseRecord::query()->count());
        $this->assertSame(1, ExpenseRecordCompetence::query()->count());
        $this->assertSame(1, OldCoreImportMapping::query()->where('entity_type', 'expense_record_competence')->count());

        $duplicates = DB::table('expense_record_competences')
            ->select('expense_record_id', 'competence_year', 'competence_month', DB::raw('COUNT(*) as rows_count'))
            ->groupBy('expense_record_id', 'competence_year', 'competence_month')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        $this->assertCount(0, $duplicates);

        DB::disconnect('old_core');

        if ($sourceDatabase !== false && is_file($sourceDatabase)) {
            @unlink($sourceDatabase);
        }
    }
}
