<?php

namespace App\Services;

use App\DTOs\PerformanceCalculationInput;
use App\DTOs\PerformanceCalculationResult;
use App\Enums\CalculationMode;
use App\Support\Numbers\ScaledNumber;
use Illuminate\Validation\ValidationException;

class PerformanceCalculationService
{
    public function calculate(PerformanceCalculationInput $input): PerformanceCalculationResult
    {
        $quantity = $this->toPositiveInteger($input->quantity, 'quantity');
        $unitAmountCents = ScaledNumber::toScaledInteger($input->unitAmount, 2, 'unit_amount');
        $totalCents = $quantity * $unitAmountCents;
        $directCostCents = ScaledNumber::toScaledInteger((string) ($input->directCost ?? '0'), 2, 'direct_cost');

        if ($directCostCents < 0) {
            throw ValidationException::withMessages([
                'direct_cost' => 'Il costo diretto prestazione deve essere maggiore o uguale a 0.',
            ]);
        }

        if ($directCostCents > $totalCents) {
            throw ValidationException::withMessages([
                'direct_cost' => 'Il costo diretto prestazione non puo superare l\'importo prestazione.',
            ]);
        }

        $netDivisibleCents = $totalCents - $directCostCents;

        if ($input->calculationMode === CalculationMode::Percentage) {
            $percentageBasisPoints = ScaledNumber::toScaledInteger((string) $input->percentageValue, 2, 'percentage_value');

            if ($percentageBasisPoints < 0 || $percentageBasisPoints > 10_000) {
                throw ValidationException::withMessages([
                    'percentage_value' => 'La percentuale deve essere compresa tra 0 e 100.',
                ]);
            }

            $professionalCents = (int) round($netDivisibleCents * $percentageBasisPoints / 10_000);
            $centerCents = $netDivisibleCents - $professionalCents;

            return new PerformanceCalculationResult(
                quantity: (string) $quantity,
                unitAmount: ScaledNumber::fromScaledInteger($unitAmountCents, 2),
                totalAmount: ScaledNumber::fromScaledInteger($totalCents, 2),
                directCost: ScaledNumber::fromScaledInteger($directCostCents, 2),
                netDivisibleAmount: ScaledNumber::fromScaledInteger($netDivisibleCents, 2),
                percentageValue: ScaledNumber::fromScaledInteger($percentageBasisPoints, 2),
                fixedAmount: null,
                professionalAmount: ScaledNumber::fromScaledInteger($professionalCents, 2),
                centerAmount: ScaledNumber::fromScaledInteger($centerCents, 2),
            );
        }

        $fixedCents = ScaledNumber::toScaledInteger((string) $input->fixedAmount, 2, 'fixed_amount');

        if ($fixedCents > $netDivisibleCents) {
            throw ValidationException::withMessages([
                'fixed_amount' => 'L\'importo forfettario non puo superare la base netta da dividere.',
            ]);
        }

        $centerCents = $netDivisibleCents - $fixedCents;

        return new PerformanceCalculationResult(
            quantity: (string) $quantity,
            unitAmount: ScaledNumber::fromScaledInteger($unitAmountCents, 2),
            totalAmount: ScaledNumber::fromScaledInteger($totalCents, 2),
            directCost: ScaledNumber::fromScaledInteger($directCostCents, 2),
            netDivisibleAmount: ScaledNumber::fromScaledInteger($netDivisibleCents, 2),
            percentageValue: null,
            fixedAmount: ScaledNumber::fromScaledInteger($fixedCents, 2),
            professionalAmount: ScaledNumber::fromScaledInteger($fixedCents, 2),
            centerAmount: ScaledNumber::fromScaledInteger($centerCents, 2),
        );
    }

    private function toPositiveInteger(?string $value, string $field): int
    {
        if ($value === null || trim($value) === '') {
            throw ValidationException::withMessages([
                $field => 'Valore obbligatorio.',
            ]);
        }

        $normalized = trim($value);
        if (! preg_match('/^\d+$/', $normalized)) {
            throw ValidationException::withMessages([
                $field => 'La quantita deve essere un numero intero.',
            ]);
        }

        $parsed = (int) $normalized;
        if ($parsed < 1) {
            throw ValidationException::withMessages([
                $field => 'La quantita deve essere maggiore o uguale a 1.',
            ]);
        }

        return $parsed;
    }
}
