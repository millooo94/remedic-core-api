<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Enums\SupportedLocale;
use App\Http\Controllers\Controller;
use App\Models\ApplicationType;
use App\Models\BlogPost;
use App\Models\FaqItem;
use App\Models\Page;
use App\Models\Professional;
use App\Models\ProfessionalPublicProfile;
use App\Models\Redirect;
use App\Models\Section;
use App\Models\Service;
use App\Models\SiteSetting;
use App\Models\SpecializationWebProfile;
use App\Services\CheckupPublicContentService;
use App\Services\ConsentConfigurationInitializer;
use App\Services\ContactCenterDataResolver;
use App\Services\ConventionPartnerPublicProjection;
use App\Services\HomePagePublicProjection;
use App\Services\LegalDocumentPublicProjection;
use App\Services\LocalizedContentResolver;
use App\Services\LocalizedRouteProjection;
use App\Services\LocalizedRouteRegistry;
use App\Services\MedicalAreaPublicService;
use App\Services\ProfessionalPublicAreaProjection;
use App\Services\PublicLocaleResolver;
use App\Services\PublicSeoResolver;
use App\Services\ServicePublicContentService;
use App\Services\SiteNavigationProjectionService;
use App\Support\Media\PublicMediaUrl;
use App\Support\Pages\LegalDocumentRegistry;
use App\Support\Pages\PageSectionRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class SiteController extends Controller
{
    public function __construct(
        private readonly MedicalAreaPublicService $medicalAreaContent,
        private readonly ProfessionalPublicAreaProjection $professionalAreas,
        private readonly ServicePublicContentService $serviceContent,
        private readonly CheckupPublicContentService $checkupContent,
        private readonly ContactCenterDataResolver $contactCenterData,
        private readonly ConventionPartnerPublicProjection $conventionPartners,
        private readonly LegalDocumentPublicProjection $legalDocuments,
        private readonly HomePagePublicProjection $homePage,
        private readonly ConsentConfigurationInitializer $consentConfiguration,
        private readonly PublicSeoResolver $seo,
        private readonly PublicLocaleResolver $locales,
        private readonly LocalizedContentResolver $localized,
        private readonly LocalizedRouteRegistry $routes,
        private readonly LocalizedRouteProjection $localizedRoutes,
        private readonly SiteNavigationProjectionService $navigation,
    ) {}

    public function settings(Request $request): JsonResponse
    {
        $locale = $this->locales->resolve($request);
        $settings = SiteSetting::current();
        $specializations = $this->medicalAreaContent->query()
            ->limit(7)
            ->get();

        return response()->json([
            'data' => [
                'settings' => $this->mapSiteSettings($settings, $request),
                'navigation' => $locale === SupportedLocale::IT ? [
                    ['label' => 'Chi siamo', 'href' => '/chi-siamo'],
                    ['label' => 'Aree mediche', 'href' => '/aree-mediche'],
                    ['label' => 'Equipe', 'href' => '/equipe'],
                    ['label' => 'Blog', 'href' => '/blog'],
                    ['label' => 'Contatti', 'href' => '/contatti'],
                ] : [],
                'footer_navigation' => $locale === SupportedLocale::IT ? [
                    ['label' => 'Chi siamo', 'href' => '/chi-siamo'],
                    ['label' => 'Aree mediche', 'href' => '/aree-mediche'],
                    ['label' => 'Prestazioni', 'href' => '/prestazioni'],
                    ['label' => 'Equipe', 'href' => '/equipe'],
                    ['label' => 'Medicina estetica', 'href' => '/medicina-estetica'],
                    ['label' => 'Blog', 'href' => '/blog'],
                    ['label' => 'Contatti', 'href' => '/contatti'],
                ] : [],
                'footer_specializations' => $specializations->map(fn (SpecializationWebProfile $profile): array => [
                    'label' => $profile->specialization->name,
                    'href' => '/aree-mediche/'.$profile->slug,
                ])->values()->all(),
            ],
        ]);
    }

    public function home(Request $request): JsonResponse
    {
        $locale = $this->locales->resolve($request);
        $specializations = $this->medicalAreaContent->query()->limit(8)->get();
        $services = $this->servicesBaseQuery()->limit(7)->get();

        $professionals = $this->listProfessionalItems($request, 4);
        $blogPosts = $this->blogPostsBaseQuery()
            ->limit(4)
            ->get();

        return response()->json([
            'data' => [
                'specializations' => $specializations->map(fn (SpecializationWebProfile $profile): array => $this->medicalAreaContent->listItem($profile, $request))->values()->all(),
                'services' => $services->map(fn (Service $service): array => $this->serviceContent->legacyListItem($service, $request))->values()->all(),
                'professionals' => $professionals,
                'blog_posts' => $blogPosts->map(fn (BlogPost $post): array => $this->mapBlogListItem($this->localized->project($post, $locale) ?? abort(404)))->values()->all(),
            ],
        ]);
    }

    public function homePage(Request $request): JsonResponse
    {
        $locale = $this->locales->resolve($request);
        $page = Page::query()->with(['translations', 'sections.translations', 'faqs.translations'])->where('internal_key', Page::HOME_INTERNAL_KEY)->firstOrFail();
        $page = $this->localized->project($page, $locale) ?? abort(404);
        abort_unless($this->localized->hasCompleteStructure($page, $locale), 404);
        $data = $this->homePage->project($page, $request);
        $data['locale'] = $locale->value;
        $data['available_locales'] = $this->localized->availableLocales($page);
        $data['localized_routes'] = $this->localizedRoutes->home($page);
        $data['seo'] = $this->seo->resolve([
            'title' => $page->title,
            'description' => $page->excerpt ?: $page->intro_text,
            'seo_title' => $page->seo_title,
            'seo_description' => $page->seo_description,
            'robots' => $page->robots,
            'og_title' => $page->og_title,
            'og_description' => $page->og_description,
            'image_url' => $this->resolveMediaPathOrUrl($page->og_image_path ?: $page->hero_image_path, $request),
        ], '/', $request);

        return response()->json(['data' => $data]);
    }

    public function search(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));

        if (mb_strlen($query) < 2) {
            return response()->json(['data' => ['results' => []]]);
        }

        $specializations = $this->medicalAreaContent->query()
            ->whereHas('specialization', fn (Builder $master) => $master->where('name', 'like', "%{$query}%"))
            ->limit(6)
            ->get()
            ->map(fn (SpecializationWebProfile $profile): array => [
                'type' => 'medical_area',
                'title' => $profile->specialization->name,
                'subtitle' => null,
                'href' => '/aree-mediche/'.$profile->slug,
            ]);

        $services = $this->servicesBaseQuery()
            ->where(function (Builder $builder) use ($query): void {
                $builder
                    ->where('display_name', 'like', "%{$query}%")
                    ->orWhere('canonical_name', 'like', "%{$query}%")
                    ->orWhereHas('webProfile', fn (Builder $profile) => $profile
                        ->where('public_slug', 'like', "%{$query}%")
                        ->orWhere('short_description', 'like', "%{$query}%"));
            })
            ->limit(6)
            ->get()
            ->map(fn (Service $service): array => [
                'type' => 'service',
                'title' => $service->publicLabel(),
                'subtitle' => null,
                'href' => '/prestazioni/'.$service->webProfile->public_slug,
            ]);

        $professionals = $this->professionalProfilesBaseQuery()
            ->where(function (Builder $builder) use ($query): void {
                $builder
                    ->where('slug', 'like', "%{$query}%")
                    ->orWhereHas('professional', function (Builder $professionalQuery) use ($query): void {
                        $professionalQuery
                            ->where('full_name', 'like', "%{$query}%")
                            ->orWhere('honorific_prefix', 'like', "%{$query}%");
                    })
                    ->orWhereHas('professional.specializations', function (Builder $specializationQuery) use ($query): void {
                        $specializationQuery->where('name', 'like', "%{$query}%");
                    });
            })
            ->limit(6)
            ->get()
            ->map(fn (ProfessionalPublicProfile $profile): array => [
                'type' => 'doctor',
                'title' => trim(($profile->professional->honorific_prefix ? $profile->professional->honorific_prefix.' ' : '').$profile->professional->full_name),
                'subtitle' => $profile->professional->specializations->pluck('name')->filter()->implode(', '),
                'href' => '/equipe/'.$profile->slug,
            ]);

        $checkups = $this->checkupContent->query()
            ->where(function (Builder $builder) use ($query): void {
                $builder->where('display_name', 'like', "%{$query}%")
                    ->orWhereHas('webProfile', fn (Builder $profile) => $profile
                        ->where('public_slug', 'like', "%{$query}%")
                        ->orWhere('short_description', 'like', "%{$query}%")
                        ->orWhere('category_label', 'like', "%{$query}%"));
            })->limit(6)->get()->map(fn ($checkup): array => [
                'type' => 'checkup', 'title' => $checkup->display_name,
                'subtitle' => $checkup->webProfile->category_label,
                'href' => '/check-up/'.$checkup->webProfile->public_slug,
            ]);

        return response()->json([
            'data' => [
                'results' => $specializations
                    ->concat($services)
                    ->concat($professionals)
                    ->concat($checkups)
                    ->values()
                    ->all(),
            ],
        ]);
    }

    public function specializations(Request $request): JsonResponse
    {
        $limit = $this->resolveLimit($request, 50);

        $profiles = $this->medicalAreaContent->query()->limit($limit)->get();

        return response()->json([
            'data' => $profiles->map(fn (SpecializationWebProfile $profile): array => $this->medicalAreaContent->listItem($profile, $request))->values()->all(),
        ]);
    }

    public function specialization(Request $request, string $slug): JsonResponse
    {
        $locale = $this->locales->resolve($request);
        $profile = $this->medicalAreaContent->query()
            ->when($locale === SupportedLocale::IT, fn (Builder $query) => $query->where('slug', $slug), fn (Builder $query) => $query->whereHas('translations', fn (Builder $translations) => $translations->where('locale', $locale->value)->where('slug', $slug)))
            ->firstOrFail();

        return response()->json([
            'data' => $this->legacyMedicalAreaDetail($profile, $request),
        ]);
    }

    public function medicalAreas(Request $request): JsonResponse
    {
        $profiles = $this->medicalAreaContent->query()
            ->limit($this->resolveLimit($request, 50))
            ->get();

        return response()->json([
            'data' => $profiles->map(fn (SpecializationWebProfile $profile): array => $this->medicalAreaContent->listItem($profile, $request))->values()->all(),
        ]);
    }

    public function medicalArea(Request $request, string $slug): JsonResponse
    {
        $locale = $this->locales->resolve($request);
        $profile = $this->medicalAreaContent->query()->when($locale === SupportedLocale::IT, fn (Builder $query) => $query->where('slug', $slug), fn (Builder $query) => $query->whereHas('translations', fn (Builder $translations) => $translations->where('locale', $locale->value)->where('slug', $slug)))->firstOrFail();

        return response()->json(['data' => $this->medicalAreaContent->detail($profile, $request)]);
    }

    public function services(Request $request): JsonResponse
    {
        // Legacy compatibility adapter. New consumers must use /prestazioni.
        $limit = $this->resolveLimit($request, 100);
        $query = $this->servicesBaseQuery();

        if ($request->boolean('featured')) {
            $query->where('is_featured', true);
        }

        $services = $query->limit($limit)->get();

        return response()->json([
            'data' => $services->map(fn (Service $service): array => $this->serviceContent->legacyListItem($service, $request))->values()->all(),
        ]);
    }

    public function prestazioni(Request $request): JsonResponse
    {
        $services = $this->servicesBaseQuery()
            ->limit($this->resolveLimit($request, 100))
            ->get();

        return response()->json([
            'data' => $services->map(fn (Service $service): array => $this->serviceContent->listItem($service, $request))->values()->all(),
        ]);
    }

    public function service(Request $request, string $slug): JsonResponse
    {
        $locale = $this->locales->resolve($request);
        $service = $this->servicesBaseQuery()
            ->when($locale === SupportedLocale::IT, fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->where('slug', $slug)
                ->orWhereHas('webProfile', fn (Builder $profile) => $profile->where('public_slug', $slug))), fn (Builder $query) => $query->whereHas('webProfile.translations', fn (Builder $translations) => $translations->where('locale', $locale->value)->where('slug', $slug)))
            ->firstOrFail();

        return response()->json([
            'data' => $this->serviceContent->legacyDetail($service, $request),
        ]);
    }

    public function prestazione(Request $request, string $slug): JsonResponse
    {
        $locale = $this->locales->resolve($request);
        $service = $this->servicesBaseQuery()
            ->when($locale === SupportedLocale::IT, fn (Builder $query) => $query->whereHas('webProfile', fn (Builder $profile) => $profile->where('public_slug', $slug)), fn (Builder $query) => $query->whereHas('webProfile.translations', fn (Builder $translations) => $translations->where('locale', $locale->value)->where('slug', $slug)))
            ->firstOrFail();

        return response()->json(['data' => $this->serviceContent->detail($service, $request)]);
    }

    public function checkups(Request $request): JsonResponse
    {
        $items = $this->checkupContent->query()->limit($this->resolveLimit($request, 100))->get();

        return response()->json(['data' => $items->map(
            fn ($checkup): array => $this->checkupContent->listItem($checkup, $request)
        )->values()->all()]);
    }

    public function checkup(Request $request, string $publicSlug): JsonResponse
    {
        $locale = $this->locales->resolve($request);
        $checkup = $this->checkupContent->query()
            ->when($locale === SupportedLocale::IT, fn (Builder $query) => $query->whereHas('webProfile', fn (Builder $profile) => $profile->where('public_slug', $publicSlug)), fn (Builder $query) => $query->whereHas('webProfile.translations', fn (Builder $translations) => $translations->where('locale', $locale->value)->where('slug', $publicSlug)))
            ->firstOrFail();

        return response()->json(['data' => $this->checkupContent->detail($checkup, $request)]);
    }

    public function professionals(Request $request): JsonResponse
    {
        $limit = $this->resolveLimit($request, 100);

        return response()->json([
            'data' => $this->listProfessionalItems($request, $limit),
        ]);
    }

    public function professional(Request $request, string $slug): JsonResponse
    {
        $locale = $this->locales->resolve($request);
        $profile = $this->professionalProfilesBaseQuery()
            ->when($locale === SupportedLocale::IT, fn (Builder $query) => $query->where('slug', $slug), fn (Builder $query) => $query->whereHas('translations', fn (Builder $translations) => $translations->where('locale', $locale->value)->where('slug', $slug)))
            ->firstOrFail();

        return response()->json([
            'data' => $this->mapProfessionalDetail($profile, $request),
        ]);
    }

    public function blogPosts(Request $request): JsonResponse
    {
        $locale = $this->locales->resolve($request);
        $limit = $this->resolveLimit($request, 50);
        $query = $this->blogPostsBaseQuery();

        if ($request->boolean('featured') && $this->blogPostsHaveFeaturedFlag()) {
            $query->where('is_featured', true);
        }

        $posts = $query->limit($limit)->get();

        return response()->json([
            'data' => $posts->map(fn (BlogPost $post): array => $this->mapBlogListItem($this->localized->project($post, $locale) ?? abort(404)))->values()->all(),
        ]);
    }

    public function blogPost(Request $request, string $slug): JsonResponse
    {
        $locale = $this->locales->resolve($request);
        $post = $this->blogPostsBaseQuery()
            ->when($locale === SupportedLocale::IT, fn (Builder $query) => $query->where('slug', $slug), fn (Builder $query) => $query->whereHas('translations', fn (Builder $translations) => $translations->where('locale', $locale->value)->where('slug', $slug)))
            ->firstOrFail();

        return response()->json([
            'data' => $this->mapBlogDetail($this->localized->project($post, $locale) ?? abort(404)),
        ]);
    }

    public function news(Request $request, string $slug): JsonResponse
    {
        return $this->typedBlogPost($slug, 'news');
    }

    public function healthPill(Request $request, string $slug): JsonResponse
    {
        return $this->typedBlogPost($slug, 'health_pill');
    }

    private function typedBlogPost(string $slug, string $type): JsonResponse
    {
        $locale = $this->locales->resolve(request());
        $post = $this->blogPostsBaseQuery()->when($locale === SupportedLocale::IT, fn (Builder $query) => $query->where('slug', $slug), fn (Builder $query) => $query->whereHas('translations', fn (Builder $translations) => $translations->where('locale', $locale->value)->where('slug', $slug)))->where('content_type', $type)->firstOrFail();

        $post = $this->localized->project($post, $locale) ?? abort(404);
        abort_unless($this->localized->hasCompleteStructure($post, $locale), 404);

        return response()->json(['data' => [...$this->mapBlogDetail($post), 'locale' => $locale->value, 'available_locales' => $this->localized->availableLocales($post)]]);
    }

    public function page(Request $request, string $slug): JsonResponse
    {
        $locale = $this->locales->resolve($request);
        $page = $this->pagesBaseQuery($locale)
            ->when($locale === SupportedLocale::IT,
                fn (Builder $query) => $query->where('slug', $slug),
                fn (Builder $query) => $query->whereHas('translations', fn (Builder $translations) => $translations->where('locale', $locale->value)->where('slug', $slug)),
            )
            ->firstOrFail();
        $page = $this->localized->project($page, $locale) ?? abort(404);
        abort_unless($this->localized->hasCompleteStructure($page, $locale), 404);

        return response()->json([
            'data' => [...$this->mapPageDetail($page), 'locale' => $locale->value, 'available_locales' => $this->localized->availableLocales($page)],
        ]);
    }

    public function resolveRedirect(Request $request): JsonResponse
    {
        $path = Redirect::normalizePathValue((string) $request->query('path', '/'));

        $redirect = Redirect::query()
            ->active()
            ->where('from_path', $path)
            ->first();

        return response()->json([
            'data' => $redirect === null ? ['matched' => false] : [
                'matched' => true,
                'destination' => $redirect->to_path,
                'status_code' => (int) $redirect->http_code,
            ],
        ]);
    }

    private function resolveLimit(Request $request, int $default): int
    {
        return max(1, min(100, $request->integer('limit', $default)));
    }

    private function servicesBaseQuery(): Builder
    {
        return $this->serviceContent->query();
    }

    private function professionalProfilesBaseQuery(): Builder
    {
        $locale = $this->locales->resolve(request());
        $query = ProfessionalPublicProfile::query()
            ->with([
                'translations',
                'sections' => fn ($query) => $query->active()->orderBy('id'),
                'professional' => fn ($query) => $query->where('is_active', true),
                'professional.specializations' => fn ($query) => $query
                    ->where('specializations.is_active', true)
                    ->whereHas('webProfile', fn (Builder $area) => $area->where('is_web_enabled', true))
                    ->orderByDesc('professional_specialization.is_primary')
                    ->orderBy('professional_specialization.sort_order')
                    ->orderBy('specializations.id'),
                'professional.specializations.webProfile.translations',
                'professional.professionalServices' => fn ($query) => $query
                    ->where('is_active', true)
                    ->where('is_visible_public', true)
                    ->orderBy('public_sort_order')
                    ->orderBy('id'),
                'professional.professionalServices.service' => fn ($query) => $query
                    ->effectivelyVisible()
                    ->orderBy('display_name'),
                'professional.professionalServices.service.webProfile.translations',
                'professional.degrees',
                'professional.academicSpecializations',
                'professional.boardRegistrations',
                'professional.careerExperiences',
                'heroCompetencies' => fn ($query) => $query->where('is_active', true),
                'approachPrinciples' => fn ($query) => $query->where('is_active', true),
                'competencies' => fn ($query) => $query->where('is_active', true),
                'scientificActivities' => fn ($query) => $query->where('is_active', true),
            ])
            ->effectivelyVisible()
            ->orderBy('id');

        return $this->localized->publicTranslations($query, $locale);
    }

    private function blogPostsBaseQuery(): Builder
    {
        $locale = $this->locales->resolve(request());
        $query = BlogPost::query()
            ->with([
                'translations',
                'sections' => fn ($query) => $query->active()->ordered()->with('translations'),
                'faqs' => fn ($query) => $query->active()->ordered()->with('translations'),
                'editorialCategory',
                'relatedServices.webProfile',
                'relatedArticles.editorialCategory',
                'relatedArticles.translations',
            ])
            ->active()
            ->published()
            ->orderByDesc('published_at')
            ->orderByDesc('id');

        if ($this->blogPostsHaveFeaturedFlag()) {
            $query->orderByDesc('is_featured');
        }

        return $this->localized->publicTranslations($query, $locale);
    }

    private function pagesBaseQuery(?SupportedLocale $locale = null): Builder
    {
        $query = Page::query()
            ->with([
                'translations',
                'sections' => fn ($query) => $query->active()->orderBy('id')->with('translations'),
                'faqs' => fn ($query) => $query->active()->orderBy('id')->with('translations'),
                'featuredReviews.review',
            ])
            ->active()
            ->orderBy('title');

        return $locale === null ? $query : $this->localized->publicTranslations($query, $locale);
    }

    private function mapSiteSettings(SiteSetting $settings, Request $request): array
    {
        $clinicName = $settings->clinic_name ?: $settings->brand_name ?: $settings->site_name ?: 'Remedic';
        $mapsUrl = $settings->google_maps_url ?: $settings->maps_url;
        $logoUrl = $this->resolveMediaPathOrUrl($settings->logo_path, $request);
        $openingHours = is_array($settings->opening_hours) ? $settings->opening_hours : [];
        $consent = $this->consentConfiguration->initialize();

        return [
            'identity' => [
                'clinic_name' => $clinicName,
                'legal_company_name' => $settings->legal_company_name,
                'business_type' => $settings->business_type,
                'vat_number' => $settings->vat_number,
                'logo_url' => $logoUrl,
            ],
            'contacts' => [
                'phone' => $settings->clinic_phone,
                'whatsapp_number' => $settings->whatsapp_number,
                'email' => $settings->clinic_email,
                'privacy_email' => $settings->privacy_email,
            ],
            'address' => [
                'formatted_address' => $settings->clinic_address,
                'street_name' => $settings->clinic_street_name,
                'street_number' => $settings->clinic_street_number,
                'postal_code' => $settings->clinic_postal_code,
                'city' => $settings->clinic_city,
                'province' => $settings->clinic_province,
                'region' => $settings->clinic_region,
                'country' => $settings->clinic_country_name,
                'country_code' => $settings->clinic_country,
                'latitude' => $settings->latitude === null ? null : (float) $settings->latitude,
                'longitude' => $settings->longitude === null ? null : (float) $settings->longitude,
                'google_maps_url' => $mapsUrl,
            ],
            'opening_hours' => $openingHours,
            'parking' => filled($settings->parking_address) ? [
                'label' => $settings->parking_label,
                'address' => $settings->parking_address,
                'description' => $settings->parking_description,
            ] : null,
            'social' => [
                'facebook_url' => $settings->facebook_url,
                'instagram_url' => $settings->instagram_url,
                'tiktok_url' => $settings->tiktok_url,
                'youtube_url' => $settings->youtube_url,
                'linkedin_url' => $settings->linkedin_url,
            ],
            'territory' => [
                'primary_city' => $settings->primary_city,
                'primary_area' => $settings->primary_area,
                'served_areas' => is_array($settings->served_areas) ? $settings->served_areas : [],
                'served_territory' => $settings->served_territory ?: $settings->province_or_area_served,
                'area_served_text' => $settings->area_served_text,
            ],
            'seo_defaults' => [
                'site_url' => $settings->site_url,
                'title' => $settings->default_meta_title,
                'description' => $settings->default_meta_description,
            ],
            'consent' => [
                'enabled' => (bool) $consent->is_enabled,
                'configuration_version' => $consent->configuration_version,
            ],
            // Deprecated flat aliases retained for existing website consumers.
            'site_name' => $clinicName,
            'brand_name' => $clinicName,
            'site_url' => $settings->site_url,
            'default_meta_title' => $settings->default_meta_title,
            'default_meta_description' => $settings->default_meta_description,
            'clinic_name' => $clinicName,
            'clinic_phone' => $settings->clinic_phone,
            'clinic_email' => $settings->clinic_email,
            'clinic_address' => $settings->clinic_address,
            'clinic_city' => $settings->clinic_city,
            'clinic_postal_code' => $settings->clinic_postal_code,
            'clinic_country' => $settings->clinic_country,
            'clinic_province' => $settings->clinic_province,
            'clinic_region' => $settings->clinic_region,
            'clinic_country_name' => $settings->clinic_country_name,
            'maps_url' => $mapsUrl,
            'google_maps_url' => $mapsUrl,
            'latitude' => $settings->latitude,
            'longitude' => $settings->longitude,
            'facebook_url' => $settings->facebook_url,
            'instagram_url' => $settings->instagram_url,
            'linkedin_url' => $settings->linkedin_url,
            'whatsapp_number' => $settings->whatsapp_number,
            'opening_hours_flat' => $openingHours,
            'parking' => filled($settings->parking_address) ? [
                'label' => $settings->parking_label,
                'address' => $settings->parking_address,
                'description' => $settings->parking_description,
            ] : null,
            'logo_url' => $logoUrl,
            'vat_number' => $settings->vat_number,
            'legal_company_name' => $settings->legal_company_name,
            'privacy_email' => $settings->privacy_email,
            'cmp_enabled' => (bool) $consent->is_enabled,
            'cmp_banner_enabled' => (bool) $consent->is_enabled,
        ];
    }

    private function legacyMedicalAreaDetail(SpecializationWebProfile $profile, Request $request): array
    {
        $canonical = $this->medicalAreaContent->detail($profile, $request);
        $sections = collect($canonical['sections'])->keyBy('key');
        $services = collect($sections->get('services')['data']['items'] ?? []);
        $professionals = collect($sections->get('equipe')['data']['items'] ?? []);

        return [
            'slug' => $canonical['slug'],
            'name' => $canonical['name'],
            'description' => $canonical['short_description'],
            'short_description' => $canonical['short_description'],
            'icon_url' => $canonical['icon_url'],
            'featured_image_url' => $canonical['featured_image_url'],
            'procedures' => $services->count(),
            'intro' => [
                'what_is' => [
                    'title' => $sections->get('scope')['data']['title'] ?? 'Di cosa si occupa '.$canonical['name'],
                    'content' => $sections->get('scope')['data']['intro'] ?? '',
                ],
                'when' => [
                    'title' => $sections->get('when_useful')['data']['title'] ?? 'Quando può essere utile',
                    'content' => $sections->get('when_useful')['data']['intro'] ?? '',
                ],
            ],
            'main_performance' => $services->first(),
            'other_performances' => $services->skip(1)->values()->all(),
            'doctors' => $professionals->all(),
            'faq' => collect($sections->get('faqs')['data']['items'] ?? [])->map(fn ($faq) => [
                'question' => $faq['question'],
                'answer' => $faq['answer'],
            ])->all(),
            'sections' => $canonical['sections'],
            'seo' => $canonical['seo'],
        ];
    }

    private function mapProfessionalListItem(ProfessionalPublicProfile $profile, Request $request): array
    {
        return $this->mapProfessionalSummary($profile->professional, $request, $profile);
    }

    private function mapProfessionalDetail(ProfessionalPublicProfile $profile, Request $request): array
    {
        $profile = $this->localized->project($profile, $this->locales->resolve($request)) ?? abort(404);

        return $this->mapProfessionalDetailFromProfessional($profile->professional, $request, $profile);
    }

    private function mapProfessionalDetailFromProfessional(
        Professional $professional,
        Request $request,
        ?ProfessionalPublicProfile $profile = null
    ): array {
        $profile ??= $professional->publicProfile;
        $sections = $profile?->sections ?? collect();
        $locale = $this->locales->resolve($request);

        $services = $professional->professionalServices
            ->filter(fn ($link) => $link->is_active && $link->is_visible_public && $link->service?->isEffectivelyVisible())
            ->map(fn ($link) => $this->publicProfessionalService($link->service, $locale))
            ->filter()
            ->map(fn (array $service): array => [
                'name' => $service['name'],
                'description' => $service['short_description'],
                'href' => $service['href'],
            ])
            ->values()
            ->all();

        return [
            'slug' => $this->resolveProfessionalSlug($professional, $profile),
            'href' => $this->routes->path('team', $this->locales->resolve($request), $this->resolveProfessionalSlug($professional, $profile)),
            'locale' => $locale->value,
            'available_locales' => $this->localized->availableLocales($profile),
            'localized_routes' => $this->localizedRoutes->content($profile, 'team', fn (ProfessionalPublicProfile $localized): string => $localized->slug),
            'name' => $professional->full_name,
            'title' => $this->resolveProfessionalTitlePrefix($profile),
            'specialization' => $this->resolveProfessionalSpecialization($professional),
            'areas' => $this->professionalAreas->areas($professional, $locale, $request)->all(),
            'short_bio' => $this->resolveProfessionalShortBio($professional, $profile),
            'full_bio' => $profile?->bio_content ?: '',
            'professional_profile' => $profile?->bio_content ?: '',
            'education' => $professional->degrees->map(fn ($degree) => $degree->title)->values()->all(),
            'clinical_experience' => '',
            'patient_approach' => $profile?->approach_content ?: '',
            'areas_of_interest' => $profile?->competencies->pluck('title')->values()->all() ?? [],
            'services' => $services,
            'publications' => $profile?->scientificActivities->map(fn ($item) => [
                'title' => $item->title,
                'year' => $item->year ?: optional($item->occurred_on)?->year,
                'journal' => $item->source ?: '',
                'url' => $item->url,
            ])->values()->all() ?? [],
            'available_services' => array_map(fn (array $service): string => $service['name'], $services),
            'sections' => $this->mapEquipeSections($professional, $profile, $request),
            'image_url' => $this->resolveProfessionalImageUrl($professional, $request, $profile),
            'seo' => [...$this->seo->resolve([
                'title' => $this->resolveProfessionalDisplayName($professional, $profile),
                'description' => $profile?->short_bio,
                'seo_title' => $profile?->seo_title,
                'seo_description' => $profile?->seo_description,
                'robots' => $profile?->robots,
                'og_title' => $profile?->og_title,
                'og_description' => $profile?->og_description,
                'twitter_title' => $profile?->twitter_title,
                'twitter_description' => $profile?->twitter_description,
                'image_url' => $this->resolveMediaPathOrUrl($profile?->og_image_path, $request) ?: $this->resolveProfessionalImageUrl($professional, $request, $profile),
                'twitter_image_url' => $this->resolveMediaPathOrUrl($profile?->twitter_image_path, $request),
            ], $this->routes->path('team', $this->locales->resolve($request), (string) $profile?->slug), $request),
                'h1' => $profile?->seo_h1,
            ],
        ];
    }

    private function mapEquipeSections(
        Professional $professional,
        ProfessionalPublicProfile $profile,
        Request $request
    ): array {
        $locale = $this->locales->resolve($request);
        $primary = $professional->specializations
            ->sortBy(fn ($item) => [($item->pivot?->is_primary ?? false) ? 0 : 1, $item->pivot?->sort_order ?? PHP_INT_MAX, $item->id])
            ->first();
        $primaryWebProfile = $primary?->webProfile;
        $services = $professional->professionalServices
            ->filter(fn ($link) => $link->is_active
                && $link->is_visible_public
                && $link->service !== null
                && $link->service->isEffectivelyVisible())
            ->map(fn ($link) => $this->publicProfessionalService($link->service, $locale))
            ->filter()
            ->values();

        $payloads = [
            'hero' => [
                'avatar_url' => $this->resolveProfessionalImageUrl($professional, $request, $profile),
                'honorific_prefix' => $professional->honorific_prefix,
                'name' => $professional->full_name,
                'primary_specialization' => $primary ? [
                    'name' => $primary->name,
                    'public_slug' => $primaryWebProfile?->public_slug,
                    'href' => $primaryWebProfile?->is_web_enabled ? $this->routes->path('medical_areas', $this->locales->resolve($request), $primaryWebProfile->public_slug) : null,
                ] : null,
                'short_bio' => $profile->short_bio,
                'competencies' => $profile->heroCompetencies->map(fn ($item) => [
                    'title' => $item->title,
                    'icon_key' => $item->icon_key,
                ])->values()->all(),
            ],
            'biography' => ['content' => $profile->bio_content],
            'approach' => [
                'content' => $profile->approach_content,
                'principles' => $profile->approachPrinciples->map(fn ($item) => [
                    'label' => $item->label,
                    'icon_key' => $item->icon_key,
                ])->values()->all(),
            ],
            'competencies' => [
                'items' => $profile->competencies->map(fn ($item) => [
                    'title' => $item->title,
                    'description' => $item->description,
                    'icon_key' => $item->icon_key,
                ])->values()->all(),
            ],
            'career' => [
                'items' => $professional->careerExperiences->map(fn ($item) => [
                    'year_from' => (int) $item->year_from,
                    'year_to' => $item->year_to !== null ? (int) $item->year_to : null,
                    'is_current' => (bool) $item->is_current,
                    'title' => $item->title,
                    'organization' => $item->organization,
                    'description' => $item->description,
                ])->values()->all(),
            ],
            'scientific_activity' => [
                'items' => $profile->scientificActivities->map(fn ($item) => [
                    'contribution_type' => $item->contribution_type?->value ?? $item->contribution_type,
                    'occurred_on' => optional($item->occurred_on)?->toDateString(),
                    'year' => $item->year,
                    'title' => $item->title,
                    'source' => $item->source,
                    'short_description' => $item->short_description,
                    'url' => $item->url,
                ])->values()->all(),
            ],
            'services' => [
                'items' => $services->all(),
            ],
        ];

        $renderable = [
            'hero' => true,
            'biography' => trim((string) $profile->bio_content) !== '',
            'approach' => trim((string) $profile->approach_content) !== '' || $profile->approachPrinciples->isNotEmpty(),
            'competencies' => $profile->competencies->isNotEmpty(),
            'career' => $professional->careerExperiences->isNotEmpty(),
            'scientific_activity' => $profile->scientificActivities->isNotEmpty(),
            'services' => $services->isNotEmpty(),
        ];

        return $profile->sections
            ->filter(fn ($section) => isset($payloads[$section->key]) && ($renderable[$section->key] ?? false))
            ->sortBy('id')->values()
            ->map(fn ($section, int $order) => [
                'key' => $section->key,
                'order' => $order,
                'data' => $section->key === 'services'
                    ? [
                        'title' => $section->title ?: 'Prestazioni',
                        'intro' => $section->content,
                        ...$payloads[$section->key],
                    ]
                    : $payloads[$section->key],
            ])->values()->all();
    }

    private function publicProfessionalService(?Service $service, SupportedLocale $locale): ?array
    {
        if ($service === null || $service->webProfile === null) {
            return null;
        }

        $profile = $this->localized->project($service->webProfile, $locale);
        if ($profile === null) {
            return null;
        }

        return [
            'slug' => $profile->public_slug,
            'href' => $this->routes->path('services', $locale, $profile->public_slug),
            'name' => $profile->localizedTranslation?->title ?: $service->publicLabel(),
            'short_description' => $profile->short_description ?: '',
        ];
    }

    private function mapBlogListItem(BlogPost $post): array
    {
        $locale = $this->locales->resolve(request());

        return [
            'slug' => $post->slug,
            'href' => $this->routes->path($post->content_type === 'health_pill' ? 'health_tips' : 'news', $locale, $post->slug),
            'title' => $post->title,
            'subtitle' => $post->subtitle ?: $post->excerpt ?: '',
            'category' => $post->editorialCategory?->name ?: $post->category_label ?: 'Blog',
            'excerpt' => $post->excerpt ?: '',
            'date' => optional($post->published_at)->translatedFormat('j F Y') ?: 'Bozza',
            'author' => $post->author_name ?: 'Redazione Remedic',
            'reviewer' => $post->reviewer_name,
            'featured' => $this->blogPostsHaveFeaturedFlag() ? (bool) ($post->getAttribute('is_featured') ?? false) : false,
            'cover_image' => $post->cover_image,
        ];
    }

    private function mapBlogDetail(BlogPost $post): array
    {
        $locale = $this->locales->resolve(request());
        $route = $post->content_type === 'health_pill' ? 'health_tips' : 'news';
        $relatedArticles = $post->relatedArticles
            ->filter(fn (BlogPost $article) => $article->id !== $post->id && $article->isPubliclyAvailable())
            ->map(function (BlogPost $article) use ($locale): ?array {
                $article = $this->localized->project($article, $locale);
                if (! $article instanceof BlogPost) {
                    return null;
                }
                $route = $article->content_type === 'health_pill' ? 'health_tips' : 'news';

                return [
                    'title' => $article->title,
                    'excerpt' => $article->excerpt ?: $article->intro_text ?: '',
                    'image_url' => $this->resolveMediaPathOrUrl($article->cover_image, request()),
                    'published_at' => $article->published_at?->toIso8601String(),
                    'date' => $article->published_at?->translatedFormat('j F Y'),
                    'category_id' => $article->editorial_category_id,
                    'category_label' => $article->editorialCategory?->name ?? $article->category_label,
                    'content_type' => $article->content_type,
                    'href' => $this->routes->path($route, $locale, $article->slug),
                ];
            })
            ->filter()
            ->values()
            ->all();

        $relatedServices = $post->relatedServices
            ->filter(fn (Service $service) => $service->isEffectivelyVisible() && $service->webProfile !== null)
            ->map(fn (Service $service): array => [
                'name' => $service->publicLabel(),
                'slug' => $service->webProfile->public_slug,
                'href' => $this->routes->path('services', $locale, $service->webProfile->public_slug),
            ])
            ->values()
            ->all();

        return [
            'slug' => $post->slug,
            'href' => $this->routes->path($route, $locale, $post->slug),
            'localized_routes' => $this->localizedRoutes->content($post, $route, fn (BlogPost $localized): string => $localized->slug),
            'title' => $post->title,
            'subtitle' => $post->subtitle ?: $post->excerpt ?: '',
            'category' => $post->editorialCategory?->name ?? $post->category_label,
            'excerpt' => $post->excerpt ?: '',
            'content' => $post->intro_text ?: $post->excerpt ?: '',
            'sections' => $post->sections->map(function (Section $section): array {
                $template = $section->template?->value ?? $section->template ?? 'text';

                return [
                    'template' => $template,
                    'title' => $section->title,
                    'body' => $section->content,
                    'image_url' => $template === 'image_text'
                        ? $this->resolveMediaPathOrUrl($section->extra_json['image_path'] ?? null, request())
                        : null,
                ];
            })->values()->all(),
            'published_at' => $post->published_at?->toIso8601String(),
            'date' => $post->published_at?->translatedFormat('j F Y'),
            'author' => $post->author_name,
            'reviewer' => $post->reviewer_name,
            'featured' => $this->blogPostsHaveFeaturedFlag() ? (bool) ($post->getAttribute('is_featured') ?? false) : false,
            'related_prestazioni' => $relatedServices,
            'related_articles' => $relatedArticles,
            'faq' => $post->faqs->map(fn (FaqItem $faq): array => [
                'question' => $faq->question,
                'answer' => $faq->answer,
            ])->values()->all(),
            'cover_image' => $this->resolveMediaPathOrUrl($post->cover_image, request()),
            'content_type' => $post->content_type,
            'editorial_category_id' => $post->editorial_category_id,
            'editorial_category_label' => $post->editorialCategory?->name,
            'seo' => [...$this->seo->resolve([
                'title' => $post->title,
                'description' => $post->excerpt ?: $post->intro_text,
                'seo_title' => $post->seo_title,
                'seo_description' => $post->seo_description,
                'robots' => $post->robots,
                'og_title' => $post->og_title,
                'og_description' => $post->og_description,
                'twitter_title' => $post->twitter_title,
                'twitter_description' => $post->twitter_description,
                'image_url' => $this->resolveMediaPathOrUrl($post->og_image_path ?: $post->cover_image, request()),
                'twitter_image_url' => $this->resolveMediaPathOrUrl($post->twitter_image_path, request()),
            ], $this->routes->path($route, $locale, $post->slug), request(), in_array($post->content_type, ['news', 'health_pill'], true) ? 'article' : 'website'),
                'h1' => $post->seo_h1,
            ],
        ];
    }

    private function mapPageDetail(Page $page): array
    {
        $locale = $this->locales->resolve(request());
        $defaultOgImagePath = SiteSetting::current()->default_og_image_path;
        $isLegal = LegalDocumentRegistry::isLegal((string) $page->internal_key);
        $sections = PageSectionRegistry::hasDefinitionsFor((string) $page->internal_key)
            ? $this->mapTypedPageSections($page)
            : $page->sections->map(fn (Section $section): array => [
                'key' => $section->key,
                'title' => $section->title,
                'subtitle' => $section->subtitle,
                'content' => $section->content,
                'extra_json' => $section->extra_json,
            ])->values()->all();

        return [
            'internal_key' => $page->internal_key,
            'slug' => $page->slug,
            'href' => $this->routes->page($locale, $page->slug),
            'localized_routes' => $this->localizedRoutes->page($page),
            'title' => $page->title,
            'template' => $page->template?->value ?? $page->template,
            'content_kind' => $page->content_kind ?? 'standard',
            ...($page->isCustom() ? ['custom_content' => [
                'html' => $page->custom_html,
                'css' => $page->custom_css,
                'javascript' => $page->custom_javascript,
            ]] : []),
            'excerpt' => $page->excerpt,
            'intro_text' => $page->intro_text,
            'hero_image_url' => $this->resolveMediaPathOrUrl($page->hero_image_path, request()),
            'hero_image_alt' => $page->hero_image_alt,
            'faq_enabled' => (bool) $page->faq_enabled,
            'sections' => $sections,
            'toc' => $isLegal ? array_values(array_map(
                fn (array $section): array => ['key' => $section['key'], 'title' => $section['title'], 'href' => '#'.$section['anchor']],
                array_filter($sections, fn (array $section): bool => ($section['key'] ?? null) !== LegalDocumentRegistry::HERO_KEY)
            )) : [],
            'faq' => $page->faq_enabled
                ? $page->faqs->map(fn (FaqItem $faq): array => [
                    'question' => $faq->question,
                    'answer' => $faq->answer,
                ])->values()->all()
                : [],
            'seo' => [...$this->seo->resolve([
                'title' => $page->title,
                'description' => $page->excerpt ?: $page->intro_text,
                'seo_title' => $page->seo_title,
                'seo_description' => $page->seo_description,
                'robots' => $page->robots,
                'og_title' => $page->og_title,
                'og_description' => $page->og_description,
                'image_url' => $this->resolveMediaPathOrUrl($page->og_image_path ?: $page->hero_image_path ?: $defaultOgImagePath, request()),
            ], $this->routes->page($locale, $page->slug), request()),
                'h1' => $page->seo_h1,
                'twitter_title' => $page->twitter_title,
                'twitter_description' => $page->twitter_description,
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function mapTypedPageSections(Page $page): array
    {
        if (LegalDocumentRegistry::isLegal((string) $page->internal_key)) {
            $sections = $page->sections->filter(fn (Section $section): bool => $section->is_active)->values();

            return $sections->map(fn (Section $section): array => $this->legalDocuments->section($page, $section))->all();
        }
        $targets = Page::query()
            ->active()
            ->whereIn('internal_key', ['why_choose_us', 'plus_health_protocol', 'privacy'])
            ->pluck('slug', 'internal_key');

        return $page->sections
            ->filter(fn (Section $section): bool => PageSectionRegistry::definition((string) $page->internal_key, $section->key) !== null)
            ->map(function (Section $section) use ($page): array {
                $definition = PageSectionRegistry::definition((string) $page->internal_key, $section->key);
                $extra = $section->extra_json ?? [];
                $mapped = [
                    'key' => $section->key,
                    'title' => $section->title,
                    'body' => $section->content,
                ];

                foreach (['eyebrow', 'link_label', 'disclaimer', 'callout_eyebrow', 'callout_body'] as $key) {
                    if (array_key_exists($key, $extra)) {
                        $mapped[$key] = $extra[$key];
                    }
                }
                if (isset($definition['media_slot'])) {
                    $mapped['media'] = [
                        'image_url' => $this->resolveMediaPathOrUrl($extra['image_path'] ?? null, request()),
                        'image_alt' => $extra['image_alt'] ?? null,
                    ];
                }
                if (isset($definition['target_internal_key'])) {
                    $targetKey = (string) ($extra['cta_target'] ?? $definition['target_internal_key']);
                    $mapped['action'] = $this->pageCta($extra['link_label'] ?? null, $targetKey, request());
                    if ($mapped['action'] !== null) {
                        $mapped['action']['target_internal_key'] = $targetKey;
                    }
                }
                if (isset($definition['actions'])) {
                    $actionTargets = array_values(array_filter([
                        $extra['primary_cta_target'] ?? null,
                        $extra['secondary_cta_target'] ?? null,
                    ], static fn (mixed $target): bool => in_array($target, ['booking', 'contact'], true)));
                    $mapped['actions'] = $actionTargets !== [] ? $actionTargets : $definition['actions'];
                    $mapped['action'] = $this->pageCta($extra['primary_cta_label'] ?? null, $extra['primary_cta_target'] ?? null, request());
                    $mapped['primary_cta'] = $mapped['action'];
                    $mapped['secondary_cta'] = $this->pageCta($extra['secondary_cta_label'] ?? null, $extra['secondary_cta_target'] ?? null, request());
                }
                if (in_array($section->key, ['model_overview', 'three_reasons', 'care_path_overview', 'person_first'], true)) {
                    $mapped['items'] = $extra['items'] ?? [];
                }
                if ($section->key === 'promise') {
                    $mapped['values'] = $extra['values'] ?? [];
                }
                if ($section->key === 'four_pillars') {
                    $mapped['pillars'] = PageSectionRegistry::protocolPillarsWithDefaults($extra['pillars'] ?? []);
                }
                if ((string) $page->internal_key === PageSectionRegistry::CAREERS_INTERNAL_KEY) {
                    if (in_array($section->key, ['professional_profiles', 'what_we_look_for'], true)) {
                        $mapped = ['key' => $section->key, 'title' => $section->title, 'data' => ['intro' => $section->content, 'subheading' => $extra['subheading'] ?? null, 'items' => $extra['items'] ?? []]];
                    }
                    if ($section->key === 'application') {
                        $privacyTarget = (string) ($extra['privacy_target'] ?? 'privacy');
                        $privacyLink = $this->navigation->target($privacyTarget);
                        $mapped = ['key' => 'application', 'title' => $section->title, 'data' => ['body' => $section->content, 'cta_label' => $extra['cta_label'] ?? 'Invia la tua candidatura', 'action' => ['type' => 'open_application_form'], 'privacy' => ['text' => $extra['privacy_text'] ?? null, 'target_internal_key' => $privacyTarget, 'href' => $privacyLink['href'] ?? null], 'application_types' => ApplicationType::query()->where('is_active', true)->publicOrder()->get()->map(fn (ApplicationType $type) => ['key' => $type->key, 'label' => $type->name])->all()]];
                    }
                }
                if ((string) $page->internal_key === PageSectionRegistry::CONTACT_INTERNAL_KEY && $section->key === 'location_and_contacts') {
                    $mapped = [
                        'key' => $section->key,
                        'title' => $section->title,
                        'data' => [
                            'intro' => $section->content,
                            'cta' => $this->pageCta($extra['cta_label'] ?? 'Contattaci', $extra['cta_target'] ?? 'contact', request()),
                            'action' => ['type' => $extra['cta_target'] ?? 'contact'],
                            'center' => $this->contactCenterData->resolve(SiteSetting::current()),
                        ],
                    ];
                }
                if ((string) $page->internal_key === PageSectionRegistry::CONVENTIONS_NETWORK_INTERNAL_KEY) {
                    if ($section->key === 'access_process') {
                        $mapped = [
                            'key' => $section->key,
                            'title' => $section->title,
                            'data' => ['intro' => $section->content, 'items' => $extra['items'] ?? []],
                        ];
                    }
                    if ($section->key === 'conventions_catalog') {
                        $mapped = [
                            'key' => $section->key,
                            'title' => $section->title,
                            'data' => ['intro' => $section->content, ...$this->conventionPartners->catalog(request())],
                        ];
                    }
                    if ($section->key === 'contact_cta') {
                        $mapped = [
                            'key' => $section->key,
                            'title' => $section->title,
                            'data' => ['body' => $section->content, 'action' => ['type' => 'contact']],
                        ];
                    }
                }
                if ($section->key === 'patient_experiences') {
                    $mapped['reviews'] = collect($page->featuredReviews)
                        ->filter(fn ($selection): bool => $selection->review?->is_available === true)
                        ->mapWithKeys(fn ($selection): array => [$selection->provider => [
                            'author_name' => $selection->review->author_name,
                            'body' => $selection->review->body,
                            'rating' => $selection->review->rating,
                            'reviewed_at' => $selection->review->reviewed_at?->toIso8601String(),
                        ]])
                        ->all();
                }

                return $mapped;
            })
            ->values()
            ->all();
    }

    /** @return array<string, mixed>|null */
    private function pageCta(mixed $label, mixed $target, Request $request): ?array
    {
        if (! is_string($label) || trim($label) === '' || ! is_string($target) || $target === '') {
            return null;
        }

        $resolved = $this->navigation->target($target, $this->locales->resolve($request));

        return $resolved['is_action']
            ? array_filter(['label' => trim($label), 'action' => $resolved['action'] ?? $target, 'href' => $resolved['href']], static fn (mixed $value): bool => $value !== null)
            : ($resolved['href'] ? ['label' => trim($label), 'href' => $resolved['href']] : null);
    }

    private function resolveMediaPathOrUrl(?string $pathOrUrl, Request $request): ?string
    {
        if (! $pathOrUrl) {
            return null;
        }

        if (filter_var($pathOrUrl, FILTER_VALIDATE_URL)) {
            return $pathOrUrl;
        }

        return PublicMediaUrl::fromPublicDisk($pathOrUrl, $request);
    }

    private function listProfessionalItems(Request $request, int $limit): array
    {
        $locale = $this->locales->resolve($request);

        return $this->professionalProfilesBaseQuery()
            ->limit($limit)
            ->get()
            ->map(fn (ProfessionalPublicProfile $profile): array => $this->mapProfessionalListItem($this->localized->project($profile, $locale) ?? abort(404), $request))
            ->values()
            ->all();
    }

    private function mapProfessionalSummary(
        Professional $professional,
        Request $request,
        ?ProfessionalPublicProfile $profile = null
    ): array {
        $profile ??= $professional->publicProfile;

        return [
            'slug' => $this->resolveProfessionalSlug($professional, $profile),
            'href' => $this->routes->path('team', $this->locales->resolve($request), $this->resolveProfessionalSlug($professional, $profile)),
            'name' => $professional->full_name,
            'title' => $this->resolveProfessionalTitlePrefix($profile),
            'specialization' => $this->resolveProfessionalSpecialization($professional),
            'short_bio' => $this->resolveProfessionalShortBio($professional, $profile),
            'image_url' => $this->resolveProfessionalImageUrl($professional, $request, $profile),
            'featured' => false,
            'available_services' => $professional->professionalServices
                ->filter(fn ($link) => $link->service?->isEffectivelyVisible())
                ->take(5)
                ->map(fn ($link) => $link->service->publicLabel())
                ->values()
                ->all(),
        ];
    }

    private function resolveProfessionalSlug(
        Professional $professional,
        ?ProfessionalPublicProfile $profile = null
    ): string {
        $profile ??= $professional->publicProfile;
        $slug = trim((string) ($profile?->slug ?? ''));

        if ($slug !== '') {
            return $slug;
        }

        return '';
    }

    private function resolveProfessionalTitlePrefix(?ProfessionalPublicProfile $profile = null): string
    {
        return trim((string) ($profile?->professional?->honorific_prefix ?? ''));
    }

    private function resolveProfessionalDisplayName(
        Professional $professional,
        ?ProfessionalPublicProfile $profile = null
    ): string {
        return trim($this->resolveProfessionalTitlePrefix($profile).' '.$professional->full_name);
    }

    private function resolveProfessionalSpecialization(Professional $professional): string
    {
        return $professional->specializations
            ->sortBy(fn ($item) => [($item->pivot?->is_primary ?? false) ? 0 : 1, $item->pivot?->sort_order ?? PHP_INT_MAX, $item->id])
            ->pluck('name')->first()
            ?: $professional->area_name
            ?: 'Specialista';
    }

    private function resolveProfessionalShortBio(
        Professional $professional,
        ?ProfessionalPublicProfile $profile = null
    ): string {
        $profile ??= $professional->publicProfile;
        $shortBio = trim((string) ($profile?->short_bio ?? ''));

        if ($shortBio !== '') {
            return $shortBio;
        }

        $specialization = $this->resolveProfessionalSpecialization($professional);

        return $specialization !== ''
            ? trim($specialization.' presso Remedic.')
            : 'Professionista Remedic.';
    }

    private function resolveProfessionalImageUrl(
        Professional $professional,
        Request $request,
        ?ProfessionalPublicProfile $profile = null
    ): ?string {
        $avatarPath = trim((string) ($professional->avatar_path ?? ''));
        if ($avatarPath !== '') {
            return PublicMediaUrl::fromPublicDisk($avatarPath, $request);
        }

        return null;
    }

    private function blogPostsHaveFeaturedFlag(): bool
    {
        return Schema::hasColumn('blog_posts', 'is_featured');
    }

    private function resolveBlogSectionType(Section $section): string
    {
        $type = $section->extra_json['type'] ?? null;

        if (is_string($type) && in_array($type, ['text', 'quote', 'list', 'info'], true)) {
            return $type;
        }

        if (is_array($section->extra_json['items'] ?? null)) {
            return 'list';
        }

        return 'text';
    }

    /**
     * @param  iterable<int, Section>  $sections
     */
    private function findSectionByKeys(iterable $sections, array $keys): ?Section
    {
        foreach ($sections as $section) {
            if (in_array($section->key, $keys, true)) {
                return $section;
            }
        }

        return null;
    }
}
