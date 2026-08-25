<?php

namespace App\Services;

use App\Models\Page;
use App\Models\Section;
use App\Models\SiteSetting;
use App\Support\Pages\LegalDocumentRegistry;

class LegalDocumentPublicProjection
{
    public function __construct(private readonly ContactCenterDataResolver $center) {}

    /** @return array<string,mixed> */
    public function section(Page $page, Section $section): array
    {
        $extra = $section->extra_json ?? [];
        if ($section->key === LegalDocumentRegistry::HERO_KEY) {
            return ['key' => $section->key, 'title' => $section->title, 'data' => ['eyebrow' => $extra['eyebrow'] ?? null, 'description' => $section->content, 'last_updated_on' => $extra['last_updated_on'] ?? null]];
        }

        return ['key' => $section->key, 'anchor' => $section->key, 'title' => $section->title, 'blocks' => array_map(fn (array $block) => $this->block($block), $extra['blocks'] ?? [])];
    }

    /** @return array<string,mixed> */
    private function block(array $block): array
    {
        if (($block['type'] ?? null) === 'bullet_list') {
            return ['type' => 'bullet_list', 'items' => $block['items'] ?? []];
        }

        return ['type' => $block['type'] ?? 'paragraph', 'parts' => array_map(fn (array $part) => $this->part($part), $block['parts'] ?? [])];
    }

    /** @return array<string,mixed> */
    private function part(array $part): array
    {
        if (($part['type'] ?? null) === 'center_reference') {
            $center = $this->center->resolve(SiteSetting::current());
            $address = $center['address'] ?? [];
            $value = match ($part['field'] ?? '') {
                'email' => $center['email'],
                'phone' => $center['phone'],
                'full_address' => $address['formatted_address'] ?: implode(', ', array_filter([
                    trim(($address['street_name'] ?? '').' '.($address['street_number'] ?? '')),
                    trim(($address['postal_code'] ?? '').' '.($address['city'] ?? '')),
                    $address['country'] ?? null,
                ])),
                default => null,
            };

            return ['type' => 'center_reference', 'field' => $part['field'] ?? null, 'value' => $value];
        } if (($part['type'] ?? null) === 'internal_reference') {
            $target = match ($part['target'] ?? '') {
                'privacy' => Page::query()->where('slug', 'privacy')->first(),'cookie' => Page::query()->where('slug', 'cookie-policy')->first(),'terms' => Page::query()->where('internal_key', 'terms_of_service')->first(),'contact' => Page::query()->where('internal_key', 'contact')->first(),default => null
            };

            return ['type' => 'internal_reference', 'target' => $part['target'] ?? null, 'label' => $target?->title, 'href' => $target?->isPubliclyAvailable() ? '/'.$target->slug : null];
        }

        return $part;
    }
}
