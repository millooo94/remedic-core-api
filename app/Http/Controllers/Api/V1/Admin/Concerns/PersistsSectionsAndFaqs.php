<?php

namespace App\Http\Controllers\Api\V1\Admin\Concerns;

use Illuminate\Database\Eloquent\Model;

trait PersistsSectionsAndFaqs
{
    protected function persistSectionsAndFaqs(Model $model, array $payload): void
    {
        $sections = $payload['sections'] ?? [];
        $faqs = $payload['faqs'] ?? [];

        $model->sections()->delete();
        foreach ($sections as $index => $section) {
            $model->sections()->create([
                'key' => $section['key'],
                'title' => $section['title'] ?? null,
                'subtitle' => $section['subtitle'] ?? null,
                'content' => $section['content'] ?? null,
                'extra_json' => $section['extra_json'] ?? null,
                'sort_order' => $section['sort_order'] ?? $index,
                'is_active' => $section['is_active'] ?? true,
            ]);
        }

        $model->faqs()->delete();
        foreach ($faqs as $index => $faq) {
            $model->faqs()->create([
                'question' => $faq['question'],
                'answer' => $faq['answer'],
                'sort_order' => $faq['sort_order'] ?? $index,
                'is_active' => $faq['is_active'] ?? true,
                'is_structured_data' => $faq['is_structured_data'] ?? true,
            ]);
        }
    }
}
