<?php

namespace App\DTOs;

readonly class PerformanceCalculationResult
{
    public function __construct(
        public string $quantity,
        public string $unitAmount,
        public string $totalAmount,
        public string $directCost,
        public string $netDivisibleAmount,
        public ?string $percentageValue,
        public ?string $fixedAmount,
        public string $professionalAmount,
        public string $centerAmount,
    ) {
    }
}
