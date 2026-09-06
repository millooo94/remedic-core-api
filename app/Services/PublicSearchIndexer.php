<?php

namespace App\Services;

use App\Enums\SupportedLocale;
use App\Models\BlogPost;
use App\Models\Checkup;
use App\Models\CheckupWebProfile;
use App\Models\ContentTranslation;
use App\Models\FaqItem;
use App\Models\FaqItemTranslation;
use App\Models\Page;
use App\Models\Professional;
use App\Models\ProfessionalPublicProfile;
use App\Models\SearchDocument;
use App\Models\Section;
use App\Models\SectionTranslation;
use App\Models\Service;
use App\Models\ServiceWebProfile;
use App\Models\SiteIndexPage;
use App\Models\SiteIndexPageTranslation;
use App\Models\Specialization;
use App\Models\SpecializationWebProfile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/** Rebuildable public projection: source records remain the only editorial truth. */
final class PublicSearchIndexer
{
    public function __construct(private readonly LocalizedContentResolver $localized, private readonly LocalizedRouteRegistry $routes, private readonly PublicSearchTextNormalizer $normalizer) {}

    public function rebuild(): int
    {
        if (! Schema::hasTable('search_documents')) {
            return 0;
        }
        SearchDocument::query()->delete();
        $count = 0;
        foreach ([Page::class, SiteIndexPage::class, SpecializationWebProfile::class, ServiceWebProfile::class, ProfessionalPublicProfile::class, CheckupWebProfile::class, BlogPost::class] as $class) {
            foreach ($class::query()->with($this->relations($class))->cursor() as $owner) {
                $count += $this->reindex($owner);
            }
        }

        return $count;
    }

    public function reindexFrom(Model $model): int
    {
        $owner = match ($model::class) {
            ContentTranslation::class => $model->translatable,
            Section::class => $model->sectionable,
            FaqItem::class => $model->faqable,
            SectionTranslation::class => $model->section?->sectionable,
            FaqItemTranslation::class => $model->faqItem?->faqable,
            SiteIndexPageTranslation::class => $model->page,
            Specialization::class => $model->webProfile,
            Service::class => $model->webProfile,
            Professional::class => $model->publicProfile,
            Checkup::class => $model->webProfile,
            default => $model,
        };

        return $owner instanceof Model ? $this->reindex($owner) : 0;
    }

    public function reindex(Model $owner): int
    {
        if (! Schema::hasTable('search_documents') || ! $this->isSupported($owner)) {
            return 0;
        }
        SearchDocument::query()->where('source_type', $owner::class)->where('source_id', $owner->getKey())->delete();
        $count = 0;
        foreach (SupportedLocale::cases() as $locale) {
            $document = $this->document($owner->loadMissing($this->relations($owner::class)), $locale);
            if ($document === null) {
                continue;
            }
            $saved = SearchDocument::query()->create($document);
            $grams = array_slice($this->normalizer->trigrams($document['title']), 0, 80);
            if ($grams !== []) {
                $saved->ngrams()->createMany(array_map(fn (string $gram): array => ['gram' => $gram], $grams));
            }
            $count++;
        }

        return $count;
    }

    public function remove(Model $owner): void
    {
        if ($this->isSupported($owner) && Schema::hasTable('search_documents')) {
            SearchDocument::query()->where('source_type', $owner::class)->where('source_id', $owner->getKey())->delete();
        }
    }

    private function document(Model $owner, SupportedLocale $locale): ?array
    {
        if (! $this->visible($owner)) {
            return null;
        }
        if ($owner instanceof BlogPost && ! in_array($owner->content_type, ['news', 'health_pill'], true)) {
            return null;
        }
        if ($owner instanceof SiteIndexPage) {
            $translation = $owner->translations->firstWhere('locale', $locale);
            if ($locale !== SupportedLocale::IT && ! $translation?->isPubliclyAvailable()) {
                return null;
            }
            $copy = clone $owner;
            if ($translation !== null) {
                $copy->forceFill($translation->only(['title', 'slug', 'content']));
            }
        } else {
            $copy = $this->localized->project($owner, $locale);
            if ($copy === null || ! $this->localized->hasCompleteStructure($copy, $locale)) {
                return null;
            }
        }

        [$type, $href, $fallbackTitle, $subtitle, $image] = $this->metadata($copy, $locale);
        $title = trim((string) ($copy->title ?: $fallbackTitle));
        if ($title === '') {
            return null;
        }
        $sections = $copy->relationLoaded('sections') ? $copy->sections->where('is_active', true)->map(fn (Section $section) => $section->title.' '.$section->subtitle.' '.$section->content)->implode(' ') : '';
        $faqs = $copy->relationLoaded('faqs') ? $copy->faqs->where('is_active', true)->map(fn (FaqItem $faq) => $faq->question.' '.$faq->answer)->implode(' ') : '';
        $body = implode(' ', array_filter([
            $title, $subtitle, $copy->excerpt ?? null, $copy->intro_text ?? null, $copy->short_description ?? null,
            $copy->body ?? null, $copy->custom_html ?? null, is_array($copy->content ?? null) ? implode(' ', $copy->content) : ($copy->content ?? null), $sections, $faqs,
        ]));
        $normalized = $this->normalizer->normalize($body);

        return [
            'source_type' => $owner::class,
            'source_id' => $owner->getKey(),
            'locale' => $locale->value,
            'result_type' => $type,
            'href' => $href,
            'title' => $title,
            'subtitle' => $subtitle,
            'excerpt' => $copy->excerpt ?? $copy->short_description ?? $copy->intro_text ?? null,
            'image_path' => $image,
            'normalized_title' => $this->normalizer->normalize($title),
            'normalized_text' => $normalized,
            'searchable_tokens' => implode(' ', $this->normalizer->tokens($body)),
        ];
    }

    /** @return array{string,string,string,?string,?string} */
    private function metadata(Model $owner, SupportedLocale $locale): array
    {
        return match ($owner::class) {
            Page::class => ['page', $locale === SupportedLocale::IT ? '/'.$owner->slug : '/'.$locale->value.'/'.$owner->slug, $owner->title, null, $owner->hero_image_path],
            SiteIndexPage::class => ['index', $this->routes->path(match ($owner->internal_key) {
                'medical_areas_index' => 'medical_areas', 'equipe_index' => 'team', 'checkups_index' => 'checkups',
                'diagnostics_index' => 'diagnostics', 'aesthetic_medicine_index' => 'aesthetic_medicine', 'news_index' => 'news', 'health_pills_index' => 'health_tips', 'conventions_network_index' => 'conventions_network',
            }, $locale), $owner->title, null, $owner->hero_poster_path],
            SpecializationWebProfile::class => ['medical_area', $this->routes->path('medical_areas', $locale, $owner->slug), $owner->specialization?->name, null, $owner->specialization?->featured_image_path],
            ServiceWebProfile::class => ['service', $this->routes->path('services', $locale, $owner->public_slug), $owner->service?->publicLabel(), null, $owner->service?->featured_image_path],
            ProfessionalPublicProfile::class => ['professional', $this->routes->path('team', $locale, $owner->slug), trim(($owner->professional?->honorific_prefix ? $owner->professional->honorific_prefix.' ' : '').$owner->professional?->full_name), $owner->title_prefix, $owner->profile_image_path],
            CheckupWebProfile::class => ['checkup', $this->routes->path('checkups', $locale, $owner->public_slug), $owner->checkup?->display_name, $owner->category_label, $owner->checkup?->featured_image_path],
            BlogPost::class => [$owner->content_type, $this->routes->path($owner->content_type === 'health_pill' ? 'health_tips' : 'news', $locale, $owner->slug), $owner->title, $owner->subtitle ?? $owner->category_label, $owner->cover_image],
        };
    }

    private function visible(Model $owner): bool
    {
        return match ($owner::class) {
            Page::class, SiteIndexPage::class, BlogPost::class => $owner->isPubliclyAvailable(),
            SpecializationWebProfile::class, ServiceWebProfile::class, ProfessionalPublicProfile::class, CheckupWebProfile::class => $owner->isEffectivelyVisible(),
            default => false,
        };
    }

    private function isSupported(Model $owner): bool
    {
        return in_array($owner::class, [Page::class, SiteIndexPage::class, SpecializationWebProfile::class, ServiceWebProfile::class, ProfessionalPublicProfile::class, CheckupWebProfile::class, BlogPost::class], true);
    }

    /** @return list<string> */
    private function relations(string $class): array
    {
        if ($class === SiteIndexPage::class) {
            return ['translations', 'sections.translations', 'faqs.translations'];
        }

        return match ($class) {
            Page::class, BlogPost::class => ['translations', 'sections.translations', 'faqs.translations'],
            SpecializationWebProfile::class => ['translations', 'sections.translations', 'faqs.translations', 'specialization'],
            ServiceWebProfile::class => ['translations', 'sections.translations', 'faqs.translations', 'service'],
            ProfessionalPublicProfile::class => ['translations', 'sections.translations', 'faqs.translations', 'professional'],
            CheckupWebProfile::class => ['translations', 'sections.translations', 'faqs.translations', 'checkup'],
            default => [],
        };
    }
}
