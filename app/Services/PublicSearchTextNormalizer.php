<?php

namespace App\Services;

final class PublicSearchTextNormalizer
{
    public function normalize(?string $value): string
    {
        $value = trim((string) $value);
        if (class_exists(\Normalizer::class)) {
            $value = \Normalizer::normalize($value, \Normalizer::FORM_KD) ?: $value;
        }
        if (class_exists(\Transliterator::class)) {
            $value = \Transliterator::create('Any-Latin; Latin-ASCII')?->transliterate($value) ?? $value;
        }

        $value = mb_strtolower($value);
        $value = preg_replace("/[\\p{Pd}\\p{Pc}'’]+/u", ' ', $value) ?? $value;
        $value = preg_replace('/[^\\p{L}\\p{N}]+/u', ' ', $value) ?? $value;

        return trim(preg_replace('/\\s+/u', ' ', $value) ?? $value);
    }

    /** @return list<string> */
    public function tokens(?string $value): array
    {
        return array_values(array_filter(array_unique(explode(' ', $this->normalize($value))), fn (string $token): bool => mb_strlen($token) >= 2));
    }

    /** @return list<string> */
    public function trigrams(string $value): array
    {
        $value = str_replace(' ', '', $this->normalize($value));
        if (mb_strlen($value) < 3) {
            return [];
        }
        $grams = [];
        for ($position = 0; $position <= mb_strlen($value) - 3; $position++) {
            $grams[] = mb_substr($value, $position, 3);
        }

        return array_values(array_unique($grams));
    }
}
