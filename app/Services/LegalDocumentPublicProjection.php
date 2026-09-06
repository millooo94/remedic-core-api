<?php

namespace App\Services;

use App\Models\Page;
use App\Models\Section;
use App\Models\SiteSetting;
use App\Support\Navigation\SiteNavigationRegistry;
use App\Support\Pages\LegalDocumentContent;
use App\Support\Pages\LegalDocumentRegistry;

class LegalDocumentPublicProjection
{
    public function __construct(private readonly ContactCenterDataResolver $center, private readonly SiteNavigationProjectionService $navigation) {}

    /** @return array<string,mixed> */
    public function section(Page $page, Section $section): array
    {
        $extra = $section->extra_json ?? [];
        if ($section->key === LegalDocumentRegistry::HERO_KEY) {
            return ['key' => $section->key, 'title' => $section->title, 'data' => ['eyebrow' => $extra['eyebrow'] ?? null, 'description' => $section->content, 'last_updated_at' => $page->updated_at?->toDateString()]];
        }

        return ['key' => $section->key, 'anchor' => $section->key, 'title' => $section->title, 'blocks' => array_map(fn (array $block) => $this->block($block), $extra['blocks'] ?? [])];
    }

    /** @return array<string,mixed> */
    private function block(array $block): array
    {
        if (($block['type'] ?? null) === 'bullet_list') {
            return ['type' => 'bullet_list', 'intro' => $block['intro'] ?? null, 'items' => $block['items'] ?? [], 'outro' => $block['outro'] ?? null];
        }

        if (array_key_exists('text', $block)) {
            return ['type' => $block['type'] ?? 'paragraph', 'parts' => $this->placeholderParts((string) $block['text'], $block['links'] ?? [])];
        }

        if (array_key_exists('parts', $block)) {
            return $this->block(LegalDocumentContent::toPlaceholderBlocks([$block])[0]);
        }

        return ['type' => $block['type'] ?? 'paragraph', 'parts' => []];
    }

    /** @param list<array<string,mixed>> $links @return list<array<string,mixed>> */
    private function placeholderParts(string $text, array $links): array
    {
        $byPlaceholder = collect($links)->keyBy('placeholder');
        $parts = [];
        $offset = 0;
        preg_match_all('/\\{\\{([1-9][0-9]*)\\}\\}/', $text, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] ?? [] as $index => $match) {
            $position = $match[1];
            if ($position > $offset) {
                $parts[] = ['type' => 'text', 'text' => substr($text, $offset, $position - $offset)];
            }
            $link = $byPlaceholder->get((int) ($matches[1][$index][0] ?? 0));
            if (is_array($link)) {
                $parts[] = $this->placeholderPart($link);
            }
            $offset = $position + strlen($match[0]);
        }
        if ($offset < strlen($text)) {
            $parts[] = ['type' => 'text', 'text' => substr($text, $offset)];
        }

        return $parts;
    }

    /** @param array<string,mixed> $link @return array<string,mixed> */
    private function placeholderPart(array $link): array
    {
        $target = (string) ($link['target'] ?? '');
        $settings = SiteSetting::current();
        if ($target === 'owner_email') {
            return ['type' => 'center_reference', 'field' => 'email', 'value' => $settings->privacy_email ?: $settings->clinic_email];
        }
        if ($target === 'owner_phone') {
            return ['type' => 'center_reference', 'field' => 'phone', 'value' => $settings->clinic_phone];
        }
        if ($target === 'center_address') {
            return $this->part(['type' => 'center_reference', 'field' => 'full_address']);
        }
        if ($target === 'external_url') {
            return ['type' => 'external_reference', 'label' => $link['label'] ?? $link['external_url'] ?? '', 'href' => $link['external_url'] ?? null];
        }

        if ($external = SiteNavigationRegistry::fixedExternalTarget($target)) {
            return ['type' => 'external_reference', 'label' => $link['label'] ?? $external['label'], 'href' => $external['href']];
        }

        $resolved = $this->navigation->target($target);
        if (in_array($target, ['phone', 'email'], true)) {
            return ['type' => 'center_reference', 'field' => $target, 'value' => $target === 'phone' ? $settings->clinic_phone : $settings->clinic_email];
        }

        return ['type' => 'internal_reference', 'target' => $target, 'label' => $link['label'] ?? SiteNavigationRegistry::TARGETS[$target] ?? $target, 'href' => $resolved['href'] ?? null];
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
