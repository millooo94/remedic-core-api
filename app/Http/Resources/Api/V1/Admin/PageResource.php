<?php

namespace App\Http\Resources\Api\V1\Admin;

use App\Models\SiteSetting;
use App\Services\ContactCenterDataResolver;
use App\Services\ContactPageMediaResolver;
use App\Services\ConventionPartnerPublicProjection;
use App\Services\SiteNavigationProjectionService;
use App\Support\Media\PublicMediaUrl;
use App\Support\Pages\HomePageRegistry;
use App\Support\Pages\LegalDocumentContent;
use App\Support\Pages\LegalDocumentRegistry;
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
            'content_kind' => $this->content_kind ?? 'standard',
            'custom_html' => $this->when($this->isCustom(), $this->custom_html),
            'custom_css' => $this->when($this->isCustom(), $this->custom_css),
            'custom_javascript' => $this->when($this->isCustom(), $this->custom_javascript),
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
            'effective_public_visibility' => $this->isPubliclyAvailable(),
            'sections' => $this->whenLoaded('sections', fn () => $this->sections->map(
                fn ($section) => $this->mapSection($section, $request)
            )->values()->all()),
            'faqs' => $this->whenLoaded('faqs', fn () => $this->faqs->map(fn ($faq) => [
                'id' => $faq->id,
                'question' => $faq->question,
                'answer' => $faq->answer,
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
            'internal_title' => $section->internal_title,
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
        if (LegalDocumentRegistry::isLegal((string) $this->internal_key)) {
            $data = $section->key === LegalDocumentRegistry::HERO_KEY
                ? ['eyebrow' => $extra['eyebrow'] ?? null, 'body' => $section->content]
                : [
                    'blocks' => LegalDocumentContent::toPlaceholderBlocks($extra['blocks'] ?? []),
                    'targets' => app(SiteNavigationProjectionService::class)->targets(),
                ];

            return [...$base, 'data' => $data];
        }
        if ((string) $this->internal_key === HomePageRegistry::INTERNAL_KEY) {
            $data = $extra;
            if (in_array($section->key, ['conventions', 'faq', 'contact', 'newsletter'], true)) {
                $data += HomePageRegistry::defaults($section->key);
            }
            $data['media'] = collect($extra['media'] ?? [])->mapWithKeys(fn ($media, $slot) => [
                $slot => [
                    'path' => $media['path'] ?? null,
                    'url' => PublicMediaUrl::fromPublicDisk($media['path'] ?? null, $request),
                    'alt' => $media['alt'] ?? null,
                ],
            ])->all();
            if (in_array($section->key, ['hero', 'center_intro', 'conventions', 'faq', 'contact', 'newsletter'], true)) {
                $defaults = HomePageRegistry::defaults($section->key);
                $targetKeys = match ($section->key) {
                    'hero', 'contact' => ['primary_cta_target', 'secondary_cta_target'],
                    'newsletter' => ['submit_target'],
                    default => ['cta_target'],
                };
                foreach ($targetKeys as $key) {
                    $data[$key] ??= $defaults[$key];
                }
                $data['targets'] = app(SiteNavigationProjectionService::class)->targets();
            }
            if ($section->key === 'contact') {
                $data['center'] = app(ContactCenterDataResolver::class)->resolve(SiteSetting::current());
                $data['shared_media'] = app(ContactPageMediaResolver::class)->resolve($request);
            }

            return [...$base, 'data' => $data];
        }
        $data = (string) $this->internal_key === PageSectionRegistry::CONTACT_INTERNAL_KEY && $section->key === 'location_and_contacts'
            ? ['intro' => $section->content, 'cta_label' => $extra['cta_label'] ?? 'Contattaci', 'cta_target' => $extra['cta_target'] ?? 'contact', 'targets' => app(SiteNavigationProjectionService::class)->targets()]
            : ['body' => $section->content];
        if ((string) $this->internal_key === PageSectionRegistry::CONVENTIONS_NETWORK_INTERNAL_KEY) {
            $data = match ($section->key) {
                'access_process' => ['intro' => $section->content, 'items' => $extra['items'] ?? []],
                'conventions_catalog' => ['intro' => $section->content, ...app(ConventionPartnerPublicProjection::class)->catalog($request)],
                'contact_cta' => ['body' => $section->content, 'action' => ['type' => 'contact']],
                default => ['body' => $section->content],
            };
        }
        if ((string) $this->internal_key === PageSectionRegistry::CAREERS_INTERNAL_KEY) {
            $data = match ($section->key) {
                'professional_profiles' => ['intro' => $section->content, 'subheading' => $extra['subheading'] ?? null, 'items' => $extra['items'] ?? []],
                'what_we_look_for' => ['intro' => $section->content, 'items' => $extra['items'] ?? []],
                'application' => [
                    'body' => $section->content,
                    'privacy_text' => $extra['privacy_text'] ?? null,
                    'privacy_target' => $extra['privacy_target'] ?? 'privacy',
                    'cta_label' => $extra['cta_label'] ?? 'Invia la tua candidatura',
                    'action' => ['type' => 'open_application_form'],
                ],
                default => ['body' => $section->content],
            };
            if ($section->key === 'application') {
                $data['targets'] = app(SiteNavigationProjectionService::class)->targets();
            }
        }
        foreach (['eyebrow', 'link_label', 'target_internal_key', 'actions', 'image_alt', 'items', 'testimonials', 'disclaimer', 'values', 'pillars', 'callout_eyebrow', 'callout_body', 'subheading', 'cta_label', 'cta_target', 'primary_cta_label', 'primary_cta_target', 'secondary_cta_label', 'secondary_cta_target', 'privacy_text', 'privacy_target'] as $key) {
            if (array_key_exists($key, $extra)) {
                $data[$key] = $extra[$key];
            }
        }
        if ((string) $this->internal_key === PageSectionRegistry::PLUS_HEALTH_PROTOCOL_INTERNAL_KEY && $section->key === 'four_pillars') {
            $data['pillars'] = PageSectionRegistry::protocolPillarsWithDefaults($extra['pillars'] ?? []);
        }
        if (isset($definition['media_slot'])) {
            $data['image_path'] = $extra['image_path'] ?? null;
            $data['image_url'] = PublicMediaUrl::fromPublicDisk($extra['image_path'] ?? null, $request);
        }
        if (isset($definition['target_internal_key']) || isset($definition['actions'])) {
            $data['targets'] = app(SiteNavigationProjectionService::class)->targets();
        }

        return [...$base, 'data' => $data];
    }
}
