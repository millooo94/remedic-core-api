<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Models\BlogPost;
use App\Models\CheckupWebProfile;
use App\Models\Page;
use App\Models\ProfessionalPublicProfile;
use App\Models\Redirect;
use App\Models\ServiceWebProfile;
use App\Models\SiteIndexPage;
use App\Models\SiteSetting;
use App\Models\SpecializationWebProfile;
use App\Services\PublicSeoResolver;
use App\Support\Media\PublicMediaUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SeoController extends Controller
{
    public function __construct(private readonly PublicSeoResolver $resolver) {}

    public function configuration(Request $request): JsonResponse
    {
        $settings = SiteSetting::current();
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
            'defaults' => $this->resolver->resolve(['title' => $settings->default_meta_title, 'description' => $settings->default_meta_description], '/', $request),
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

    public function sitemap(): JsonResponse
    {
        $settings = SiteSetting::current();
        if (! $settings->seo_sitemap_enabled) {
            return response()->json(['data' => ['enabled' => false, 'items' => []]]);
        }
        $items = collect([['path' => '/', 'type' => 'homepage', 'last_modified' => $settings->updated_at]])
            ->concat(Page::query()->active()->published()->where(fn ($query) => $query->whereNull('internal_key')->orWhere('internal_key', '!=', Page::HOME_INTERNAL_KEY))->get()->reject(fn (Page $page): bool => $page->isLegacyCheckupPage())->map(fn (Page $page) => ['path' => $page->canonical_url ?: '/'.$page->slug, 'type' => 'page', 'last_modified' => $page->updated_at]))
            ->concat(SiteIndexPage::query()->active()->published()->get()->map(fn (SiteIndexPage $page) => ['path' => $page->canonical_url ?: '/'.$page->slug, 'type' => 'index', 'last_modified' => $page->updated_at]))
            ->concat(SpecializationWebProfile::query()->effectivelyVisible()->get()->map(fn (SpecializationWebProfile $profile) => ['path' => '/aree-mediche/'.$profile->slug, 'type' => 'medical_area', 'last_modified' => $profile->updated_at]))
            ->concat(ServiceWebProfile::query()->effectivelyVisible()->get()->map(fn (ServiceWebProfile $profile) => ['path' => '/prestazioni/'.$profile->public_slug, 'type' => 'service', 'last_modified' => $profile->updated_at]))
            ->concat(ProfessionalPublicProfile::query()->effectivelyVisible()->get()->map(fn (ProfessionalPublicProfile $profile) => ['path' => '/equipe/'.$profile->slug, 'type' => 'professional', 'last_modified' => $profile->updated_at]))
            ->concat(CheckupWebProfile::query()->effectivelyVisible()->get()->map(fn (CheckupWebProfile $profile) => ['path' => '/check-up/'.$profile->public_slug, 'type' => 'checkup', 'last_modified' => $profile->updated_at]))
            ->concat(BlogPost::query()->active()->published()->get()->map(fn (BlogPost $post) => ['path' => $post->canonicalHref(), 'type' => $post->content_type, 'last_modified' => $post->updated_at]));
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
}
