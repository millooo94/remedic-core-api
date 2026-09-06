<?php

namespace App\Services;

use App\Models\ConventionPartner;
use App\Models\ConventionPartnerWebProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConventionPartnerWebContentService
{
    public function upsert(ConventionPartner $partner, array $payload): ConventionPartnerWebProfile
    {
        return DB::transaction(function () use ($partner, $payload): ConventionPartnerWebProfile {
            $faqs = $payload['faqs'] ?? [];
            unset($payload['faqs']);
            // Individual public pages do not exist yet. The enabled flag is an
            // editorial readiness state only; it never enables a public route.
            $payload['canonical_url'] = null;
            $profile = $partner->webProfile()->firstOrNew();
            $profile->fill($payload);
            $profile->save();
            $this->syncFaqs($profile, $faqs);

            return $profile;
        });
    }

    /** @param list<array<string,mixed>> $items */
    private function syncFaqs(ConventionPartnerWebProfile $profile, array $items): void
    {
        $existing = $profile->faqs()->get()->keyBy('id');
        $kept = [];
        foreach (array_values($items) as $order => $item) {
            $id = isset($item['id']) ? (int) $item['id'] : null;
            if ($id !== null && ! $existing->has($id)) {
                throw ValidationException::withMessages(["faqs.{$order}.id" => 'La FAQ non appartiene a questa convenzione.']);
            }
            $faq = $id ? $existing->get($id) : $profile->faqs()->make();
            $faq->fill(['question' => trim((string) $item['question']), 'answer' => trim((string) $item['answer']), 'sort_order' => $order, 'is_active' => (bool) ($item['is_active'] ?? true), 'is_structured_data' => (bool) ($item['is_structured_data'] ?? true)])->save();
            $kept[] = $faq->id;
        }
        $profile->faqs()->whereNotIn('id', $kept ?: [0])->delete();
    }
}
