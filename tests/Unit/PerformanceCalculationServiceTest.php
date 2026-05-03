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
            new PerformanceCalculationInput(CalculationMode::Percentage, '1', '100', '0', '70'),
        );

        $this->assertSame('100.00', $result->totalAmount);
        $this->assertSame('0.00', $result->directCost);
        $this->assertSame('100.00', $result->netDivisibleAmount);
        $this->assertSame('70.00', $result->professionalAmount);
        $this->assertSame('30.00', $result->centerAmount);
    }

    #[Test]
    public function it_calculates_percentage_60(): void
    {
        $result = app(PerformanceCalculationService::class)->calculate(
            new PerformanceCalculationInput(CalculationMode::Percentage, '1', '100', '0', '60'),
        );

        $this->assertSame('60.00', $result->professionalAmount);
        $this->assertSame('40.00', $result->centerAmount);
    }

    #[Test]
    public function it_calculates_percentage_50(): void
    {
        $result = app(PerformanceCalculationService::class)->calculate(
            new PerformanceCalculationInput(CalculationMode::Percentage, '1', '100', '0', '50'),
        );

        $this->assertSame('50.00', $result->professionalAmount);
        $this->assertSame('50.00', $result->centerAmount);
    }

    #[Test]
    public function it_calculates_custom_percentage_33(): void
    {
        $result = app(PerformanceCalculationService::class)->calculate(
            new PerformanceCalculationInput(CalculationMode::Percentage, '1', '100', '0', '33'),
        );

        $this->assertSame('33.00', $result->professionalAmount);
        $this->assertSame('67.00', $result->centerAmount);
    }

    #[Test]
    public function it_calculates_fixed_amount_30(): void
    {
        $result = app(PerformanceCalculationService::class)->calculate(
            new PerformanceCalculationInput(CalculationMode::Fixed, '1', '100', '0', null, '30'),
        );

        $this->assertSame('30.00', $result->professionalAmount);
        $this->assertSame('70.00', $result->centerAmount);
    }

    #[Test]
    public function it_calculates_fixed_amount_100(): void
    {
        $result = app(PerformanceCalculationService::class)->calculate(
            new PerformanceCalculationInput(CalculationMode::Fixed, '1', '100', '0', null, '100'),
        );

        $this->assertSame('100.00', $result->professionalAmount);
        $this->assertSame('0.00', $result->centerAmount);
    }

    #[Test]
    public function it_rejects_fixed_amount_higher_than_total(): void
    {
        $this->expectException(ValidationException::class);

        app(PerformanceCalculationService::class)->calculate(
            new PerformanceCalculationInput(CalculationMode::Fixed, '1', '100', '0', null, '101'),
        );
    }

    #[Test]
    public function it_calculates_percentage_after_direct_cost(): void
    {
        $result = app(PerformanceCalculationService::class)->calculate(
            new PerformanceCalculationInput(CalculationMode::Percentage, '1', '100', '20', '70'),
        );

        $this->assertSame('100.00', $result->totalAmount);
        $this->assertSame('20.00', $result->directCost);
        $this->assertSame('80.00', $result->netDivisibleAmount);
        $this->assertSame('56.00', $result->professionalAmount);
        $this->assertSame('24.00', $result->centerAmount);
    }

    #[Test]
    public function it_rejects_direct_cost_higher_than_total(): void
    {
        $this->expectException(ValidationException::class);

        app(PerformanceCalculationService::class)->calculate(
            new PerformanceCalculationInput(CalculationMode::Percentage, '1', '100', '101', '70'),
        );
    }
}
