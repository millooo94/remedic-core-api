<?php

namespace Tests\Unit;

use App\Models\Professional;
use App\Models\Service;
use App\Services\CatalogImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CatalogImportServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_normalizes_duplicates_without_merging_control_or_combined_visits(): void
    {
        Professional::factory()->create([
            'first_name' => 'Giuseppe',
            'last_name' => 'Bottaro',
            'full_name' => 'Bottaro Giuseppe',
            'area_name' => 'Cardiologia',
        ]);

        $report = app(CatalogImportService::class)->import([
            ['professional' => 'Bottaro Giuseppe', 'area' => 'cardiologia', 'service_name' => 'visita cardiologica', 'price' => '120'],
            ['professional' => 'Bottaro Giuseppe', 'area' => 'cardiologia', 'service_name' => 'Visita cardiologica', 'price' => '120'],
            ['professional' => 'Bottaro Giuseppe', 'area' => 'cardiologia', 'service_name' => 'Visita cardiologica di controllo', 'price' => '90'],
            ['professional' => 'Bottaro Giuseppe', 'area' => 'cardiologia', 'service_name' => 'Visita cardiologica + ECG', 'price' => '140'],
            ['professional' => 'Bottaro Giuseppe', 'area' => 'cardiologia', 'service_name' => 'laser depilazione gambe', 'price' => 'da 80'],
            ['professional' => 'Bottaro Giuseppe', 'area' => 'cardiologia', 'service_name' => 'Laser epilazione gambe', 'price' => 'da 80'],
        ], 'test');

        $this->assertSame(6, $report['source_records']);
        $this->assertTrue(Service::query()->where('display_name', 'Visita cardiologica')->exists());
        $this->assertTrue(Service::query()->where('display_name', 'Visita cardiologica di controllo')->exists());
        $this->assertTrue(Service::query()->where('display_name', 'Visita cardiologica + ECG')->exists());
        $this->assertTrue(Service::query()->where('display_name', 'Epilazione laser gambe')->exists());
        $this->assertCount(4, Service::query()->get());
        $this->assertGreaterThanOrEqual(1, $report['aliases_created']);
    }
}
