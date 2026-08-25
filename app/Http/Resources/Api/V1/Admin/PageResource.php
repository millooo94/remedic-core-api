<?php

namespace App\Http\Resources\Api\V1\Admin;

use App\Models\SiteSetting;
use App\Services\ContactCenterDataResolver;
use App\Services\ConventionPartnerPublicProjection;
use App\Support\Media\PublicMediaUrl;
use App\Support\Pages\PageSectionRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'internal_key' => $this->internal_key,
            'title' => $this->title,
            'slug' => $this->slug,
            'template' => $this->template?->value ?? $this->template,
            'excerpt' => $this->excerpt,
            'intro_text' => $this->intro_text,
            'hero_image_path' => $this->hero_image_path,
            'hero_image_url' => PublicMediaUrl::fromPublicDisk($this->hero_image_path, $request),
            'hero_image_alt' => $this->hero_image_alt,
            'seo_title' => $this->seo_title,
            'seo_description' => $this->seo_description,
            'seo_h1' => $this->seo_h1,
            'canonical_url' => $this->canonical_url,
            'robots' => $this->robots?->value ?? $this->robots,
            'og_title' => $this->og_title,
            'og_description' => $this->og_description,
            'og_image_path' => $this->og_image_path,
            'og_image_url' => PublicMediaUrl::fromPublicDisk($this->og_image_path, $request),
            'twitter_title' => $this->twitter_title,
            'twitter_description' => $this->twitter_description,
            'twitter_image_path' => $this->twitter_image_path,
            'twitter_image_url' => PublicMediaUrl::fromPublicDisk($this->twitter_image_path, $request),
            'meta_author' => $this->meta_author,
            'meta_creator' => $this->meta_creator,
            'meta_keywords' => $this->meta_keywords,
            'faq_enabled' => (bool) $this->faq_enabled,
            'is_active' => (bool) $this->is_active,
            'published_at' => optional($this->published_at)?->toIso8601String(),
            'publication_state' => $this->publicationState()->value,
            'effective_public_visibility' => $this->isPubliclyAvailable(),
            'sections' => $this->whenLoaded('sections', fn () => $this->sections->map(
                fn ($section) => $this->mapSection($section, $request)
            )->values()->all()),
            'faqs' => $this->whenLoaded('faqs', fn () => $this->faqs->map(fn ($faq) => [
                'id' => $faq->id,
                'question' => $faq->question,
                'answer' => $faq->answer,
                'sort_order' => $faq->sort_order,
                'is_active' => (bool) $faq->is_active,
                'is_structured_data' => (bool) $faq->is_structured_data,
            ])->values()->all()),
            'created_at' => optional($this->created_at)?->toIso8601String(),
            'updated_at' => optional($this->updated_at)?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function mapSection(object $section, Request $request): array
    {
        $base = [
            'id' => $section->id,
            'key' => $section->key,
            'title' => $section->title,
            'sort_order' => $section->sort_order,
            'is_active' => (bool) $section->is_active,
        ];
        $definition = PageSectionRegistry::definition((string) $this->internal_key, $section->key);

        if ($definition === null) {
            return [...$base, ...[
                'subtitle' => $section->subtitle,
                'content' => $section->content,
                'extra_json' => $section->extra_json,
            ]];
        }

        $extra = $section->extra_json ?? [];
        $data = (string) $this->internal_key === PageSectionRegistry::CONTACT_INTERNAL_KEY && $section->key === 'location_and_contacts'
            ? ['intro' => $section->content, 'action' => ['type' => 'contact'], 'center' => app(ContactCenterDataResolver::class)->resolve(SiteSetting::current())]
            : ['body' => $section->content];
        if ((string) $this->internal_key === PageSectionRegistry::CONVENTIONS_NETWORK_INTERNAL_KEY) {
            $data = match ($section->key) {
                'access_process' => ['intro' => $section->content, 'items' => $extra['items'] ?? []],
                'conventions_catalog' => ['intro' => $section->content, ...app(ConventionPartnerPublicProjection::class)->catalog($request)],
                'contact_cta' => ['body' => $section->content, 'action' => ['type' => 'contact']],
                default => ['body' => $section->content],
            };
        }
        foreach (['eyebrow', 'link_label', 'target_internal_key', 'actions', 'image_alt', 'items', 'testimonials', 'disclaimer', 'values', 'pillars', 'callout_eyebrow', 'callout_body'] as $key) {
            if (array_key_exists($key, $extra)) {
                $data[$key] = $extra[$key];
            }
        }
        if (isset($definition['media_slot'])) {
            $data['image_path'] = $extra['image_path'] ?? null;
            $data['image_url'] = PublicMediaUrl::fromPublicDisk($extra['image_path'] ?? null, $request);
        }

        return [...$base, 'data' => $data];
    }
}
