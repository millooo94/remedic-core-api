<?php

namespace Tests\Unit;

use App\DTOs\PerformanceCalculationInput;
use App\Enums\CalculationMode;
use App\Services\PerformanceCalculationService;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PerformanceCalculationServiceTest extends TestCase
{
    #[Test]
    public function it_calculates_percentage_70(): void
    {
        $result = app(PerformanceCalculationService::class)->calculate(
            new PerformanceCalculationInput(CalculationMode::Percentage, '1', '100', '70'),
        );

        $this->assertSame('100.00', $result->totalAmount);
        $this->assertSame('70.00', $result->professionalAmount);
        $this->assertSame('30.00', $result->centerAmount);
    }

    #[Test]
    public function it_calculates_percentage_60(): void
    {
        $result = app(PerformanceCalculationService::class)->calculate(
            new PerformanceCalculationInput(CalculationMode::Percentage, '1', '100', '60'),
        );

        $this->assertSame('60.00', $result->professionalAmount);
        $this->assertSame('40.00', $result->centerAmount);
    }

    #[Test]
    public function it_calculates_percentage_50(): void
    {
        $result = app(PerformanceCalculationService::class)->calculate(
            new PerformanceCalculationInput(CalculationMode::Percentage, '1', '100', '50'),
        );

        $this->assertSame('50.00', $result->professionalAmount);
        $this->assertSame('50.00', $result->centerAmount);
    }

    #[Test]
    public function it_calculates_custom_percentage_33(): void
    {
        $result = app(PerformanceCalculationService::class)->calculate(
            new PerformanceCalculationInput(CalculationMode::Percentage, '1', '100', '33'),
        );

        $this->assertSame('33.00', $result->professionalAmount);
        $this->assertSame('67.00', $result->centerAmount);
    }

    #[Test]
    public function it_calculates_fixed_amount_30(): void
    {
        $result = app(PerformanceCalculationService::class)->calculate(
            new PerformanceCalculationInput(CalculationMode::Fixed, '1', '100', null, '30'),
        );

        $this->assertSame('30.00', $result->professionalAmount);
        $this->assertSame('70.00', $result->centerAmount);
    }

    #[Test]
    public function it_calculates_fixed_amount_100(): void
    {
        $result = app(PerformanceCalculationService::class)->calculate(
            new PerformanceCalculationInput(CalculationMode::Fixed, '1', '100', null, '100'),
        );

        $this->assertSame('100.00', $result->professionalAmount);
        $this->assertSame('0.00', $result->centerAmount);
    }

    #[Test]
    public function it_rejects_fixed_amount_higher_than_total(): void
    {
        $this->expectException(ValidationException::class);

        app(PerformanceCalculationService::class)->calculate(
            new PerformanceCalculationInput(CalculationMode::Fixed, '1', '100', null, '101'),
        );
    }
}
