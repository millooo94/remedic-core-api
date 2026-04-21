<?php

namespace App\Services;

use App\DTOs\PerformanceCalculationInput;
use App\DTOs\PerformanceCalculationResult;
use App\Enums\CalculationMode;
use Illuminate\Validation\ValidationException;

class PerformanceCalculationService
{
    public function calculate(PerformanceCalculationInput $input): PerformanceCalculationResult
    {
        $quantityHundredths = $this->toScaledInteger($input->quantity, 2, 'quantity');
        $unitAmountCents = $this->toScaledInteger($input->unitAmount, 2, 'unit_amount');
        $totalCents = (int) round(($quantityHundredths * $unitAmountCents) / 100);

        if ($input->calculationMode === CalculationMode::Percentage) {
            $percentageBasisPoints = $this->toScaledInteger((string) $input->percentageValue, 2, 'percentage_value');

            if ($percentageBasisPoints < 0 || $percentageBasisPoints > 10_000) {
                throw ValidationException::withMessages([
                    'percentage_value' => 'La percentuale deve essere compresa tra 0 e 100.',
                ]);
            }

            $professionalCents = (int) round($totalCents * $percentageBasisPoints / 10_000);
            $centerCents = $totalCents - $professionalCents;

            return new PerformanceCalculationResult(
                quantity: $this->fromScaledInteger($quantityHundredths, 2),
                unitAmount: $this->fromScaledInteger($unitAmountCents, 2),
                totalAmount: $this->fromScaledInteger($totalCents, 2),
                percentageValue: $this->fromScaledInteger($percentageBasisPoints, 2),
                fixedAmount: null,
                professionalAmount: $this->fromScaledInteger($professionalCents, 2),
                centerAmount: $this->fromScaledInteger($centerCents, 2),
            );
        }

        $fixedCents = $this->toScaledInteger((string) $input->fixedAmount, 2, 'fixed_amount');

        if ($fixedCents > $totalCents) {
            throw ValidationException::withMessages([
                'fixed_amount' => 'L\'importo forfettario non puo superare l\'importo prestazione.',
            ]);
        }

        $centerCents = $totalCents - $fixedCents;

        return new PerformanceCalculationResult(
            quantity: $this->fromScaledInteger($quantityHundredths, 2),
            unitAmount: $this->fromScaledInteger($unitAmountCents, 2),
            totalAmount: $this->fromScaledInteger($totalCents, 2),
            percentageValue: null,
            fixedAmount: $this->fromScaledInteger($fixedCents, 2),
            professionalAmount: $this->fromScaledInteger($fixedCents, 2),
            centerAmount: $this->fromScaledInteger($centerCents, 2),
        );
    }

    private function toScaledInteger(?string $value, int $scale, string $field): int
    {
        if ($value === null || $value === '') {
            throw ValidationException::withMessages([
                $field => 'Valore obbligatorio.',
            ]);
        }

        $normalized = str_replace(',', '.', trim($value));

        if (! is_numeric($normalized)) {
            throw ValidationException::withMessages([
                $field => 'Valore numerico non valido.',
            ]);
        }

        return (int) round(((float) $normalized) * (10 ** $scale));
    }

    private function fromScaledInteger(int $value, int $scale): string
    {
        return number_format($value / (10 ** $scale), $scale, '.', '');
    }
}
