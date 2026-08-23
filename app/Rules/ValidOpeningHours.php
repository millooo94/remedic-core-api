<?php

namespace App\Rules;

use Closure;
use DateTimeZone;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidOpeningHours implements ValidationRule
{
    private const DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value) || ($value['version'] ?? null) !== 1) {
            $fail('Il formato degli orari di apertura non è valido.');

            return;
        }

        $timezone = $value['timezone'] ?? null;
        if (! is_string($timezone) || ! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            $fail('Il fuso orario non è valido.');
        }

        $days = $value['days'] ?? null;
        if (! is_array($days) || array_keys($days) !== self::DAYS) {
            $fail('Gli orari devono contenere tutti e sette i giorni in ordine.');

            return;
        }

        foreach ($days as $day => $intervals) {
            if (! is_array($intervals)) {
                $fail("Gli intervalli di {$day} non sono validi.");

                continue;
            }
            $previousEnd = null;
            $seen = [];
            foreach ($intervals as $interval) {
                $start = is_array($interval) ? ($interval['start'] ?? null) : null;
                $end = is_array($interval) ? ($interval['end'] ?? null) : null;
                if (! is_string($start) || ! preg_match('/^(?:[01]\\d|2[0-3]):[0-5]\\d$/', $start)
                    || ! is_string($end) || ! preg_match('/^(?:[01]\\d|2[0-3]):[0-5]\\d$/', $end)) {
                    $fail("Un intervallo di {$day} non usa il formato HH:MM.");

                    continue;
                }
                if ($start >= $end) {
                    $fail("L'orario di inizio deve precedere quello di fine per {$day}.");
                }
                if ($previousEnd !== null && $start < $previousEnd) {
                    $fail("Gli intervalli di {$day} devono essere ordinati e non sovrapposti.");
                }
                $key = $start.'-'.$end;
                if (isset($seen[$key])) {
                    $fail("Gli intervalli di {$day} non possono essere duplicati.");
                }
                $seen[$key] = true;
                $previousEnd = $end;
            }
        }
    }
}
