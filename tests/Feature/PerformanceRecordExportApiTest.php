<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Patient;
use App\Models\Professional;
use App\Models\ProfessionalService;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\PerformanceRecordExportService;
use App\Services\PerformanceRecordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PerformanceRecordExportApiTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_exports_the_same_filtered_performance_records_used_by_the_list(): void
    {
        $user = User::factory()->create(['role' => UserRole::Admin]);
        Sanctum::actingAs($user);

        $category = ServiceCategory::factory()->create([
            'name' => 'Cardiologia export',
            'slug' => 'cardiologia-export',
        ]);

        $professionalA = Professional::factory()->create([
            'full_name' => 'Bottaro Giuseppe',
            'first_name' => 'Giuseppe',
            'last_name' => 'Bottaro',
            'area_name' => 'Cardiologia',
        ]);

        $professionalB = Professional::factory()->create([
            'full_name' => 'Verdi Marta',
            'first_name' => 'Marta',
            'last_name' => 'Verdi',
            'area_name' => 'Cardiologia',
        ]);

        $service = Service::factory()->create([
            'category_id' => $category->id,
            'display_name' => 'Visita cardiologica export',
            'canonical_name' => 'Visita cardiologica export',
            'slug' => 'visita-cardiologica-export',
        ]);

        ProfessionalService::query()->create([
            'professional_id' => $professionalA->id,
            'service_id' => $service->id,
            'price_amount' => 100,
            'is_active' => true,
        ]);

        ProfessionalService::query()->create([
            'professional_id' => $professionalB->id,
            'service_id' => $service->id,
            'price_amount' => 100,
            'is_active' => true,
        ]);

        $performanceService = app(PerformanceRecordService::class);

        $performanceService->create([
            'performed_at' => '2026-05-10',
            'professional_id' => $professionalA->id,
            'service_id' => $service->id,
            'patient_ids' => $this->createPatientIds(1),
            'quantity' => 1,
            'unit_amount' => 100,
            'payment_method' => 'cash',
            'payment_status' => 'da_pagare',
            'calculation_mode' => 'percentage',
            'percentage_value' => 70,
            'is_invoiced' => false,
            'is_black' => false,
            'notes' => 'white-not-invoiced',
        ], $user);

        $performanceService->create([
            'performed_at' => '2026-05-11',
            'professional_id' => $professionalB->id,
            'service_id' => $service->id,
            'patient_ids' => $this->createPatientIds(1),
            'quantity' => 1,
            'unit_amount' => 120,
            'payment_method' => 'cash',
            'payment_status' => 'da_pagare',
            'calculation_mode' => 'percentage',
            'percentage_value' => 70,
            'is_invoiced' => false,
            'is_black' => true,
            'notes' => 'black-not-invoiced',
        ], $user);

        $performanceService->create([
            'performed_at' => '2026-05-12',
            'professional_id' => $professionalA->id,
            'service_id' => $service->id,
            'patient_ids' => $this->createPatientIds(1),
            'quantity' => 1,
            'unit_amount' => 140,
            'payment_method' => 'cash',
            'payment_status' => 'pagata',
            'calculation_mode' => 'percentage',
            'percentage_value' => 70,
            'is_invoiced' => true,
            'is_black' => false,
            'notes' => 'white-invoiced-liquidated',
        ], $user);

        $exportData = app(PerformanceRecordExportService::class)->build([
            'date_from' => '2026-05-01',
            'date_to' => '2026-05-31',
            'invoice_filter' => 'not_invoiced',
        ]);

        $this->assertCount(1, $exportData['records']);
        $this->assertSame('Bottaro Giuseppe', $exportData['records'][0]['professional_name']);
        $this->assertSame('White', $exportData['records'][0]['fiscal_type_label']);
        $this->assertSame(1, $exportData['totals']['professional_count']);

        $blackOnly = app(PerformanceRecordExportService::class)->build([
            'date_from' => '2026-05-01',
            'date_to' => '2026-05-31',
            'fiscal_filter' => 'black',
        ]);

        $this->assertCount(1, $blackOnly['records']);
        $this->assertSame('Black', $blackOnly['records'][0]['fiscal_type_label']);

        $blackWithLockedInvoice = app(PerformanceRecordExportService::class)->build([
            'date_from' => '2026-05-01',
            'date_to' => '2026-05-31',
            'fiscal_filter' => 'black',
            'invoice_filter' => 'not_invoiced',
        ]);

        $this->assertCount(1, $blackWithLockedInvoice['records']);
        $this->assertSame('Black', $blackWithLockedInvoice['records'][0]['fiscal_type_label']);

        $liquidatedInvoiced = app(PerformanceRecordExportService::class)->build([
            'date_from' => '2026-05-01',
            'date_to' => '2026-05-31',
            'invoice_filter' => 'invoiced',
            'liquidation_filter' => 'liquidated',
        ]);

        $this->assertCount(1, $liquidatedInvoiced['records']);
        $this->assertSame('Fatturata', $liquidatedInvoiced['records'][0]['invoicing_status']);
        $this->assertSame('Liquidata', $liquidatedInvoiced['records'][0]['payment_status_label']);

        $this->getJson('/api/v1/performance-records/export/preview?date_from=2026-05-01&date_to=2026-05-31&page=2&per_page=1')
            ->assertOk()
            ->assertJsonPath('totals.performance_count', 3)
            ->assertJsonPath('totals.professional_amount', 252)
            ->assertJsonPath('totals.white.professional_amount', 168)
            ->assertJsonPath('totals.black.professional_amount', 84)
            ->assertJsonPath('fiscal_breakdown.0.label', 'White')
            ->assertJsonPath('fiscal_breakdown.1.label', 'Black')
            ->assertJsonPath('fiscal_breakdown.2.label', 'Totale')
            ->assertJsonPath('professional_subtotals.0.professional_amount', 168)
            ->assertJsonPath('professional_subtotals.0.fiscal_breakdown.0.professional_amount', 168)
            ->assertJsonPath('professional_subtotals.1.black.professional_amount', 84);

        $this->get('/api/v1/performance-records/export/pdf?date_from=2026-05-01&date_to=2026-05-31&invoice_filter=not_invoiced')
            ->assertOk();

        $this->get('/api/v1/performance-records/export/xlsx?date_from=2026-05-01&date_to=2026-05-31&invoice_filter=not_invoiced')
            ->assertOk();
    }

    #[Test]
    public function it_breaks_down_exported_professional_quota_by_white_and_black_even_with_advanced_splits(): void
    {
        $user = User::factory()->create(['role' => UserRole::Admin]);
        Sanctum::actingAs($user);

        $category = ServiceCategory::factory()->create([
            'name' => 'Neurologia export split',
            'slug' => 'neurologia-export-split',
        ]);

        $professionalA = Professional::factory()->create([
            'full_name' => 'Rossi Mario',
            'first_name' => 'Mario',
            'last_name' => 'Rossi',
            'area_name' => 'Neurologia',
        ]);

        $professionalB = Professional::factory()->create([
            'full_name' => 'Bianchi Laura',
            'first_name' => 'Laura',
            'last_name' => 'Bianchi',
            'area_name' => 'Neurologia',
        ]);

        $service = Service::factory()->create([
            'category_id' => $category->id,
            'display_name' => 'Elettromiografia export split',
            'canonical_name' => 'Elettromiografia export split',
            'slug' => 'elettromiografia-export-split',
        ]);

        ProfessionalService::query()->create([
            'professional_id' => $professionalA->id,
            'service_id' => $service->id,
            'price_amount' => 140,
            'is_active' => true,
        ]);

        ProfessionalService::query()->create([
            'professional_id' => $professionalB->id,
            'service_id' => $service->id,
            'price_amount' => 140,
            'is_active' => true,
        ]);

        $performanceService = app(PerformanceRecordService::class);

        $performanceService->create([
            'performed_at' => '2026-05-20',
            'professional_id' => $professionalA->id,
            'service_id' => $service->id,
            'patient_ids' => $this->createPatientIds(1),
            'quantity' => 1,
            'unit_amount' => 120,
            'payment_method' => 'cash',
            'payment_status' => 'da_pagare',
            'calculation_mode' => 'percentage',
            'percentage_value' => 70,
            'is_invoiced' => false,
            'is_black' => true,
            'notes' => 'black-standard',
        ], $user);

        $performanceService->create([
            'performed_at' => '2026-05-21',
            'professional_id' => $professionalA->id,
            'service_id' => $service->id,
            'patient_ids' => $this->createPatientIds(1),
            'quantity' => 1,
            'unit_amount' => 120,
            'direct_cost' => 20,
            'payment_method' => 'card',
            'payment_status' => 'da_pagare',
            'split_mode' => 'advanced',
            'advanced_splits' => [
                ['subject_type' => 'professional', 'professional_id' => $professionalA->id, 'amount' => 60],
                ['subject_type' => 'professional', 'professional_id' => $professionalB->id, 'amount' => 40],
            ],
            'is_invoiced' => false,
            'is_black' => false,
            'notes' => 'white-advanced',
        ], $user);

        $preview = app(PerformanceRecordExportService::class)->build([
            'date_from' => '2026-05-01',
            'date_to' => '2026-05-31',
        ]);

        $this->assertSame(2, $preview['totals']['performance_count']);
        $this->assertSame(84.0, $preview['totals']['black']['professional_amount']);
        $this->assertSame(100.0, $preview['totals']['white']['professional_amount']);
        $this->assertSame(184.0, $preview['totals']['total']['professional_amount']);

        /** @var Collection<string, array<string, mixed>> $subtotals */
        $subtotals = collect($preview['professional_subtotals'])->keyBy('professional_name');

        $this->assertSame(144.0, $subtotals['Rossi Mario']['total']['professional_amount']);
        $this->assertSame(60.0, $subtotals['Rossi Mario']['white']['professional_amount']);
        $this->assertSame(84.0, $subtotals['Rossi Mario']['black']['professional_amount']);
        $this->assertSame(2, $subtotals['Rossi Mario']['total']['performance_count']);

        $this->assertSame(40.0, $subtotals['Bianchi Laura']['total']['professional_amount']);
        $this->assertSame(40.0, $subtotals['Bianchi Laura']['white']['professional_amount']);
        $this->assertSame(0.0, $subtotals['Bianchi Laura']['black']['professional_amount']);
        $this->assertSame(1, $subtotals['Bianchi Laura']['total']['performance_count']);
    }

    private function createPatientIds(int $count): array
    {
        return Patient::factory()
            ->count($count)
            ->create()
            ->pluck('id')
            ->all();
    }
}
