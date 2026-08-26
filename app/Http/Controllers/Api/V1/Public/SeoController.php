<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Enums\SupportedLocale;
use App\Http\Controllers\Controller;
use App\Models\BlogPost;
use App\Models\CheckupWebProfile;
use App\Models\GlobalSeoTranslation;
use App\Models\Page;
use App\Models\ProfessionalPublicProfile;
use App\Models\Redirect;
use App\Models\ServiceWebProfile;
use App\Models\SiteIndexPage;
use App\Models\SiteSetting;
use App\Models\SpecializationWebProfile;
use App\Services\LocalizedContentResolver;
use App\Services\LocalizedRouteRegistry;
use App\Services\PublicLocaleResolver;
use App\Services\PublicSeoResolver;
use App\Support\Media\PublicMediaUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SeoController extends Controller
{
    public function __construct(private readonly PublicSeoResolver $resolver, private readonly PublicLocaleResolver $locales, private readonly LocalizedContentResolver $localized, private readonly LocalizedRouteRegistry $routes) {}

    public function configuration(Request $request): JsonResponse
    {
        $locale = $this->locales->resolve($request);
        $settings = SiteSetting::current();
        $translation = GlobalSeoTranslation::query()->where('locale', $locale->value)->where('publication_state', 'published')->first();
        abort_if($locale->value !== 'it' && $translation === null, 404);
        $siteName = $settings->site_name ?: $settings->brand_name ?: $settings->clinic_name;
        $sameAs = collect([$settings->facebook_url, $settings->instagram_url, $settings->tiktok_url, $settings->youtube_url, $settings->linkedin_url])->filter()->values()->all();
        $organization = array_filter([
            '@context' => 'https://schema.org',
            '@type' => $settings->business_type ?: 'MedicalOrganization',
            'name' => $siteName,
            'url' => $this->resolver->canonicalUrl($settings->site_url, '/'),
            'telephone' => $settings->clinic_phone,
            'email' => $settings->clinic_email,
            'logo' => PublicMediaUrl::fromPublicDisk($settings->logo_path, $request),
            'address' => array_filter(['@type' => 'PostalAddress', 'streetAddress' => $settings->clinic_address, 'addressLocality' => $settings->clinic_city, 'postalCode' => $settings->clinic_postal_code, 'addressCountry' => $settings->clinic_country]),
            'geo' => $settings->latitude !== null && $settings->longitude !== null ? ['@type' => 'GeoCoordinates', 'latitude' => (float) $settings->latitude, 'longitude' => (float) $settings->longitude] : null,
            'sameAs' => $sameAs ?: null,
        ], fn ($value) => $value !== null && $value !== [] && $value !== '');

        return response()->json(['data' => [
            'locale' => $locale->value,
            'defaults' => $this->resolver->resolve(['title' => $translation?->default_meta_title ?: $settings->default_meta_title, 'description' => $translation?->default_meta_description ?: $settings->default_meta_description], $locale->value === 'it' ? '/' : '/'.$locale->value, $request),
            'structured_data' => [
                $organization,
                array_filter(['@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => $siteName, 'url' => $this->resolver->canonicalUrl($settings->site_url, '/')], fn ($value) => $value !== null && $value !== ''),
            ],
        ]]);
    }

    public function robots(): JsonResponse
    {
        $settings = SiteSetting::current();

        return response()->json(['data' => [
            'indexing_enabled' => (bool) $settings->seo_indexing_enabled,
            'directives' => $settings->seo_indexing_enabled ? ['index', 'follow'] : ['noindex', 'nofollow'],
            'sitemap_enabled' => (bool) $settings->seo_sitemap_enabled,
            'sitemap_endpoint' => '/api/v1/public/seo/sitemap',
        ]]);
    }

    public function sitemap(Request $request): JsonResponse
    {
        $locale = $this->locales->resolve($request);
        $settings = SiteSetting::current();
        if (! $settings->seo_sitemap_enabled) {
            return response()->json(['data' => ['enabled' => false, 'items' => []]]);
        }
        $items = collect([['path' => $this->routes->homepage($locale), 'type' => 'homepage', 'last_modified' => $settings->updated_at]])
            ->concat($this->localized->publicTranslations(Page::query()->with('translations')->active()->published(), $locale)->where(fn ($query) => $query->whereNull('internal_key')->orWhere('internal_key', '!=', Page::HOME_INTERNAL_KEY))->get()->reject(fn (Page $page): bool => $page->isLegacyCheckupPage())->map(function (Page $page) use ($locale): array {
                $translation = $page->translations->firstWhere('locale', $locale);

                return ['path' => $locale->value === 'it' ? ($page->canonical_url ?: '/'.$page->slug) : '/'.$locale->value.'/'.$translation->slug, 'type' => 'page', 'last_modified' => $page->updated_at];
            }))
            ->concat(SiteIndexPage::query()->with('translations')->active()->published()->get()->filter(function (SiteIndexPage $page) use ($locale): bool {
                $translation = $page->translations->firstWhere('locale', $locale);

                return $locale->value === 'it' || $translation?->isPubliclyAvailable();
            })->map(fn (SiteIndexPage $page) => ['path' => $this->indexPath($page->internal_key, $locale), 'type' => 'index', 'last_modified' => $page->updated_at]))
            ->concat($this->localized->publicTranslations(SpecializationWebProfile::query()->with('translations')->effectivelyVisible(), $locale)->get()->map(function (SpecializationWebProfile $profile) use ($locale): array {
                $localized = $this->localized->project($profile, $locale);

                return ['path' => $this->routes->path('medical_areas', $locale, $localized->slug), 'type' => 'medical_area', 'last_modified' => $profile->updated_at];
            }))
            ->concat($this->localized->publicTranslations(ServiceWebProfile::query()->with('translations')->effectivelyVisible(), $locale)->get()->map(function (ServiceWebProfile $profile) use ($locale): array {
                $localized = $this->localized->project($profile, $locale);

                return ['path' => $this->routes->path('services', $locale, $localized->public_slug), 'type' => 'service', 'last_modified' => $profile->updated_at];
            }))
            ->concat($this->localized->publicTranslations(ProfessionalPublicProfile::query()->with('translations')->effectivelyVisible(), $locale)->get()->map(function (ProfessionalPublicProfile $profile) use ($locale): array {
                $localized = $this->localized->project($profile, $locale);

                return ['path' => $this->routes->path('team', $locale, $localized->slug), 'type' => 'professional', 'last_modified' => $profile->updated_at];
            }))
            ->concat($this->localized->publicTranslations(CheckupWebProfile::query()->with('translations')->effectivelyVisible(), $locale)->get()->map(function (CheckupWebProfile $profile) use ($locale): array {
                $localized = $this->localized->project($profile, $locale);

                return ['path' => $this->routes->path('checkups', $locale, $localized->public_slug), 'type' => 'checkup', 'last_modified' => $profile->updated_at];
            }))
            ->concat($this->localized->publicTranslations(BlogPost::query()->with('translations')->active()->published(), $locale)->get()->map(function (BlogPost $post) use ($locale): array {
                $translation = $post->translations->firstWhere('locale', $locale);
                $slug = $locale->value === 'it' ? $post->slug : $translation->slug;
                $key = $post->content_type === 'news' ? 'news' : 'health_tips';

                return ['path' => $this->routes->path($key, $locale, $slug), 'type' => $post->content_type, 'last_modified' => $post->updated_at];
            }));
        $redirectSources = Redirect::query()->active()->pluck('from_path')->all();
        $items = $items->map(function (array $item): array {
            $item['path'] = $this->resolver->normalizePath($item['path']);
            $item['url'] = $this->resolver->canonicalUrl(SiteSetting::current()->site_url, $item['path']);
            $item['last_modified'] = $item['last_modified']?->toIso8601String();

            return $item;
        })->reject(fn (array $item): bool => in_array($item['path'], $redirectSources, true))
            ->unique('path')->values();

        return response()->json(['data' => ['enabled' => true, 'items' => $items]]);
    }

    private function indexPath(string $key, SupportedLocale $locale): string
    {
        return $this->routes->path(match ($key) {
            'medical_areas_index' => 'medical_areas',
            'equipe_index' => 'team',
            'checkups_index' => 'checkups',
            'diagnostics_index' => 'diagnostics',
            'aesthetic_medicine_index' => 'aesthetic_medicine',
            'news_index' => 'news',
            'health_pills_index' => 'health_tips',
        }, $locale);
    }
}
