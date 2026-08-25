<?php

namespace App\Services;

use App\Models\Page;
use App\Models\Redirect;
use App\Models\Section;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use LogicException;

/** Explicit, reusable cleanup for the retired legacy Page catalog. */
class ObsoletePageCleanupService
{
    public const DELETE_SLUGS = [
        'chi-siamo',
        'medicina-di-genere',
        'equipe',
        'specializzazioni',
        'medicina-di-genere-donna',
        'medicina-di-genere-uomo',
        'medicina-di-genere-prevenzione-per-eta',
        'prestazioni',
        'contatti',
        'check-up',
        'check-up-donna',
        'check-up-uomo',
        'check-up-personalizzato',
        'check-up-cardiologico',
        'check-up-dermatologico',
        'check-up-ginecologico',
        'check-up-urologico',
        'check-up-endocrinologico',
    ];

    private const PRESERVED_INTERNAL_KEYS = [
        Page::CENTER_INTERNAL_KEY,
        Page::WHY_CHOOSE_US_INTERNAL_KEY,
        Page::PLUS_HEALTH_PROTOCOL_INTERNAL_KEY,
    ];

    private const PRESERVED_LEGAL_SLUGS = ['privacy', 'cookie-policy'];

    /** @return array{pages: list<array<string, mixed>>, unexpected_pages: list<array<string, mixed>>} */
    public function inventory(): array
    {
        $pages = Page::query()
            ->with(['sections:id,sectionable_id,sectionable_type,key,extra_json', 'faqs:id,faqable_id,faqable_type'])
            ->withCount(['sections', 'faqs'])
            ->orderBy('id')
            ->get();

        $mapped = $pages->map(function (Page $page): array {
            $ownedRedirects = Redirect::query()
                ->where('source_type', Redirect::SOURCE_TYPE_PAGE)
                ->where('source_id', $page->id)
                ->get(['id', 'from_path', 'to_path', 'is_automatic'])
                ->map(fn (Redirect $redirect): array => [
                    'id' => $redirect->id,
                    'from_path' => $redirect->from_path,
                    'to_path' => $redirect->to_path,
                    'is_automatic' => (bool) $redirect->is_automatic,
                ])->all();
            $media = $this->ownedSectionMediaPaths($page);

            return [
                'id' => $page->id,
                'internal_key' => $page->internal_key,
                'slug' => $page->slug,
                'title' => $page->title,
                'sections_count' => $page->sections_count,
                'faqs_count' => $page->faqs_count,
                'media' => $media,
                'automatic_redirects_count' => count(array_filter($ownedRedirects, fn (array $redirect): bool => $redirect['is_automatic'])),
                'manual_redirects_count' => count(array_filter($ownedRedirects, fn (array $redirect): bool => ! $redirect['is_automatic'])),
            ];
        })->all();

        return [
            'pages' => $mapped,
            'unexpected_pages' => array_values(array_filter($mapped, fn (array $page): bool => ! $this->isKnownPage($page))),
        ];
    }

    /** @return array{pages: list<array<string, mixed>>, deleted_count: int, sections_deleted: int, faqs_deleted: int, automatic_redirects_deleted: int, media_deleted: list<string>} */
    public function cleanup(): array
    {
        $inventory = $this->inventory();
        if ($inventory['unexpected_pages'] !== []) {
            throw new LogicException('Sono presenti Page non classificate: il cleanup è stato bloccato.');
        }

        $targets = array_values(array_filter($inventory['pages'], fn (array $page): bool => in_array($page['slug'], self::DELETE_SLUGS, true)));
        $mediaCandidates = [];
        $stats = DB::transaction(function () use (&$mediaCandidates): array {
            $pages = Page::query()->whereIn('slug', self::DELETE_SLUGS)->with(['sections', 'faqs'])->get();
            $sectionsDeleted = 0;
            $faqsDeleted = 0;
            $automaticRedirectsDeleted = 0;

            foreach ($pages as $page) {
                $sectionsDeleted += $page->sections->count();
                $faqsDeleted += $page->faqs->count();
                $mediaCandidates = [...$mediaCandidates, ...$this->ownedSectionMediaPaths($page)];
                $automaticRedirectsDeleted += Redirect::query()
                    ->automatic()
                    ->where('source_type', Redirect::SOURCE_TYPE_PAGE)
                    ->where('source_id', $page->id)
                    ->delete();
                $page->delete();
            }

            return [
                'deleted_count' => $pages->count(),
                'sections_deleted' => $sectionsDeleted,
                'faqs_deleted' => $faqsDeleted,
                'automatic_redirects_deleted' => $automaticRedirectsDeleted,
            ];
        });

        $mediaDeleted = $this->deleteUnreferencedOwnedMedia(array_values(array_unique($mediaCandidates)));

        return ['pages' => $targets, ...$stats, 'media_deleted' => $mediaDeleted];
    }

    /** @param array<string, mixed> $page */
    private function isKnownPage(array $page): bool
    {
        return in_array($page['slug'], self::DELETE_SLUGS, true)
            || in_array($page['internal_key'], self::PRESERVED_INTERNAL_KEYS, true)
            || in_array($page['slug'], self::PRESERVED_LEGAL_SLUGS, true);
    }

    /** @return list<string> */
    private function ownedSectionMediaPaths(Page $page): array
    {
        return $page->sections
            ->map(fn (Section $section): mixed => $section->extra_json['image_path'] ?? null)
            ->filter(fn (mixed $path): bool => is_string($path) && str_starts_with($path, "pages/{$page->id}/"))
            ->unique()
            ->values()
            ->all();
    }

    /** @param list<string> $paths @return list<string> */
    private function deleteUnreferencedOwnedMedia(array $paths): array
    {
        $deleted = [];
        foreach ($paths as $path) {
            if (Section::query()->where('extra_json->image_path', $path)->exists()) {
                continue;
            }

            Storage::disk('public')->delete($path);
            $deleted[] = $path;
        }

        return $deleted;
    }
}
