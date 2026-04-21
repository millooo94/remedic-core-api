<?php

namespace App\DTOs;

use App\Enums\CalculationMode;

readonly class PerformanceCalculationInput
{
    public function __construct(
        public CalculationMode $calculationMode,
        public string $quantity,
        public string $unitAmount,
        public ?string $percentageValue = null,
        public ?string $fixedAmount = null,
    ) {
    }
}
