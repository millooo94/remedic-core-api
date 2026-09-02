<?php

namespace App\Http\Controllers\Api\V1\Admin\Concerns;

use Illuminate\Database\Eloquent\Model;

trait PersistsSectionsAndFaqs
{
    protected function persistSectionsAndFaqs(Model $model, array $payload): void
    {
        if (array_key_exists('sections', $payload)) {
            $sections = $payload['sections'] ?? [];
            $model->sections()->delete();

            foreach ($sections as $section) {
                $model->sections()->create([
                    'key' => $section['key'],
                    'title' => $section['title'] ?? null,
                    'subtitle' => $section['subtitle'] ?? null,
                    'content' => $section['content'] ?? null,
                    'extra_json' => $section['extra_json'] ?? null,
                    'is_active' => $section['is_active'] ?? true,
                ]);
            }
        }

        if (array_key_exists('faqs', $payload)) {
            $faqs = $payload['faqs'] ?? [];
            $model->faqs()->delete();

            foreach ($faqs as $faq) {
                $model->faqs()->create([
                    'question' => isset($faq['question']) ? trim((string) $faq['question']) : '',
                    'answer' => isset($faq['answer']) ? trim((string) $faq['answer']) : '',
                    'is_active' => $this->normalizeBooleanValue($faq['is_active'] ?? null, true),
                    'is_structured_data' => $this->normalizeBooleanValue($faq['is_structured_data'] ?? null, true),
                ]);
            }
        }
    }

    private function normalizeBooleanValue(mixed $value, bool $default): bool
    {
        if ($value === null) {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        $normalized = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $normalized ?? $default;
    }
}
