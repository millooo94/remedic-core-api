<?php

namespace App\Http\Controllers\Api\V1\Public;

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
use App\Services\ContactCenterDataResolver;
use App\Services\ConventionPartnerPublicProjection;
use App\Services\HomePagePublicProjection;
use App\Services\LegalDocumentPublicProjection;
use App\Services\MedicalAreaPublicService;
use App\Services\ServicePublicContentService;
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
        private readonly ServicePublicContentService $serviceContent,
        private readonly CheckupPublicContentService $checkupContent,
        private readonly ContactCenterDataResolver $contactCenterData,
        private readonly ConventionPartnerPublicProjection $conventionPartners,
        private readonly LegalDocumentPublicProjection $legalDocuments,
        private readonly HomePagePublicProjection $homePage,
    ) {}

    public function settings(Request $request): JsonResponse
    {
        $settings = SiteSetting::current();
        $specializations = $this->medicalAreaContent->query()
            ->limit(7)
            ->get();

        return response()->json([
            'data' => [
                'settings' => $this->mapSiteSettings($settings, $request),
                'navigation' => [
                    ['label' => 'Chi siamo', 'href' => '/chi-siamo'],
                    ['label' => 'Aree mediche', 'href' => '/aree-mediche'],
                    ['label' => 'Equipe', 'href' => '/equipe'],
                    ['label' => 'Blog', 'href' => '/blog'],
                    ['label' => 'Contatti', 'href' => '/contatti'],
                ],
                'footer_navigation' => [
                    ['label' => 'Chi siamo', 'href' => '/chi-siamo'],
                    ['label' => 'Aree mediche', 'href' => '/aree-mediche'],
                    ['label' => 'Prestazioni', 'href' => '/prestazioni'],
                    ['label' => 'Equipe', 'href' => '/equipe'],
                    ['label' => 'Medicina estetica', 'href' => '/medicina-estetica'],
                    ['label' => 'Blog', 'href' => '/blog'],
                    ['label' => 'Contatti', 'href' => '/contatti'],
                ],
                'footer_specializations' => $specializations->map(fn (SpecializationWebProfile $profile): array => [
                    'label' => $profile->specialization->name,
                    'href' => '/aree-mediche/'.$profile->slug,
                ])->values()->all(),
            ],
        ]);
    }

    public function home(Request $request): JsonResponse
    {
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
                'blog_posts' => $blogPosts->map(fn (BlogPost $post): array => $this->mapBlogListItem($post))->values()->all(),
            ],
        ]);
    }

    public function homePage(Request $request): JsonResponse
    {
        $page = Page::query()->where('internal_key', Page::HOME_INTERNAL_KEY)->active()->published()->firstOrFail();

        return response()->json(['data' => $this->homePage->project($page, $request)]);
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
        $profile = $this->medicalAreaContent->query()
            ->where('slug', $slug)
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
        $profile = $this->medicalAreaContent->query()->where('slug', $slug)->firstOrFail();

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
        $service = $this->servicesBaseQuery()
            ->where(fn (Builder $query) => $query
                ->where('slug', $slug)
                ->orWhereHas('webProfile', fn (Builder $profile) => $profile->where('public_slug', $slug)))
            ->firstOrFail();

        return response()->json([
            'data' => $this->serviceContent->legacyDetail($service, $request),
        ]);
    }

    public function prestazione(Request $request, string $slug): JsonResponse
    {
        $service = $this->servicesBaseQuery()
            ->whereHas('webProfile', fn (Builder $profile) => $profile->where('public_slug', $slug))
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
        $checkup = $this->checkupContent->query()
            ->whereHas('webProfile', fn (Builder $profile) => $profile->where('public_slug', $publicSlug))
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
        $profile = $this->professionalProfilesBaseQuery()
            ->where('slug', $slug)
            ->firstOrFail();

        return response()->json([
            'data' => $this->mapProfessionalDetail($profile, $request),
        ]);
    }

    public function blogPosts(Request $request): JsonResponse
    {
        $limit = $this->resolveLimit($request, 50);
        $query = $this->blogPostsBaseQuery();

        if ($request->boolean('featured') && $this->blogPostsHaveFeaturedFlag()) {
            $query->where('is_featured', true);
        }

        $posts = $query->limit($limit)->get();

        return response()->json([
            'data' => $posts->map(fn (BlogPost $post): array => $this->mapBlogListItem($post))->values()->all(),
        ]);
    }

    public function blogPost(Request $request, string $slug): JsonResponse
    {
        $post = $this->blogPostsBaseQuery()
            ->where('slug', $slug)
            ->firstOrFail();

        return response()->json([
            'data' => $this->mapBlogDetail($post),
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
        $post = $this->blogPostsBaseQuery()->where('slug', $slug)->where('content_type', $type)->firstOrFail();

        return response()->json(['data' => $this->mapBlogDetail($post)]);
    }

    public function page(Request $request, string $slug): JsonResponse
    {
        $page = $this->pagesBaseQuery()
            ->where(function (Builder $query) use ($slug): void {
                $query
                    ->where('slug', $slug)
                    ->orWhere('internal_key', $slug);
            })
            ->firstOrFail();

        return response()->json([
            'data' => $this->mapPageDetail($page),
        ]);
    }

    public function resolveRedirect(Request $request): JsonResponse
    {
        $path = Redirect::normalizePathValue((string) $request->query('path', '/'));

        $redirect = Redirect::query()
            ->active()
            ->where('from_path', $path)
            ->first();

        if ($redirect === null) {
            return response()->json([
                'data' => null,
            ], 404);
        }

        return response()->json([
            'data' => [
                'from_path' => $redirect->from_path,
                'to_path' => $redirect->to_path,
                'http_code' => (int) $redirect->http_code,
                'is_automatic' => (bool) $redirect->is_automatic,
                'source_type' => $redirect->source_type,
                'source_id' => $redirect->source_id !== null ? (int) $redirect->source_id : null,
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
        return ProfessionalPublicProfile::query()
            ->with([
                'sections' => fn ($query) => $query->active()->ordered(),
                'professional' => fn ($query) => $query->where('is_active', true),
                'professional.specializations' => fn ($query) => $query
                    ->where('specializations.is_active', true)
                    ->whereHas('webProfile', fn (Builder $area) => $area->where('is_web_enabled', true))
                    ->orderByDesc('professional_specialization.is_primary')
                    ->orderBy('professional_specialization.sort_order')
                    ->orderBy('specializations.id'),
                'professional.specializations.webProfile',
                'professional.professionalServices' => fn ($query) => $query
                    ->where('is_active', true)
                    ->where('is_visible_public', true)
                    ->orderBy('public_sort_order')
                    ->orderBy('id'),
                'professional.professionalServices.service' => fn ($query) => $query
                    ->effectivelyVisible()
                    ->orderBy('display_name'),
                'professional.professionalServices.service.webProfile',
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
            ->orderBy('sort_order')
            ->orderBy('slug');
    }

    private function blogPostsBaseQuery(): Builder
    {
        $query = BlogPost::query()
            ->with([
                'sections' => fn ($query) => $query->active()->ordered(),
                'faqs' => fn ($query) => $query->active()->ordered(),
                'relatedServices.webProfile',
                'relatedArticles',
            ])
            ->active()
            ->published()
            ->orderByDesc('published_at')
            ->orderByDesc('id');

        if ($this->blogPostsHaveFeaturedFlag()) {
            $query->orderByDesc('is_featured');
        }

        return $query;
    }

    private function pagesBaseQuery(): Builder
    {
        return Page::query()
            ->with([
                'sections' => fn ($query) => $query->active()->ordered(),
                'faqs' => fn ($query) => $query->active()->ordered(),
            ])
            ->active()
            ->published()
            ->orderBy('title');
    }

    private function mapSiteSettings(SiteSetting $settings, Request $request): array
    {
        $clinicName = $settings->clinic_name ?: $settings->brand_name ?: $settings->site_name ?: 'Remedic';
        $mapsUrl = $settings->google_maps_url ?: $settings->maps_url;
        $logoUrl = $this->resolveMediaPathOrUrl($settings->logo_path, $request);
        $openingHours = is_array($settings->opening_hours) ? $settings->opening_hours : [];

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
                'locality_phrase' => $settings->default_locality_phrase,
            ],
            'consent' => [
                'enabled' => (bool) $settings->cmp_enabled,
                'banner_enabled' => (bool) $settings->cmp_banner_enabled,
                'cookie_name' => $settings->cmp_consent_cookie_name,
                'cookie_ttl_days' => $settings->cmp_consent_cookie_ttl_days,
                'show_reject_all_button' => (bool) $settings->cmp_show_reject_all_button,
                'show_accept_all_button' => (bool) $settings->cmp_show_accept_all_button,
                'show_manage_preferences_button' => (bool) $settings->cmp_show_manage_preferences_button,
                'default_locale' => $settings->cmp_default_locale,
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
            'cmp_enabled' => (bool) $settings->cmp_enabled,
            'cmp_banner_enabled' => (bool) $settings->cmp_banner_enabled,
            'cmp_consent_cookie_name' => $settings->cmp_consent_cookie_name,
            'cmp_consent_cookie_ttl_days' => $settings->cmp_consent_cookie_ttl_days,
            'cmp_show_reject_all_button' => (bool) $settings->cmp_show_reject_all_button,
            'cmp_show_accept_all_button' => (bool) $settings->cmp_show_accept_all_button,
            'cmp_show_manage_preferences_button' => (bool) $settings->cmp_show_manage_preferences_button,
            'cmp_default_locale' => $settings->cmp_default_locale,
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
        return $this->mapProfessionalDetailFromProfessional($profile->professional, $request, $profile);
    }

    private function mapProfessionalDetailFromProfessional(
        Professional $professional,
        Request $request,
        ?ProfessionalPublicProfile $profile = null
    ): array {
        $profile ??= $professional->publicProfile;
        $sections = $profile?->sections ?? collect();

        $services = $professional->professionalServices
            ->filter(fn ($link) => $link->is_active && $link->is_visible_public && $link->service?->isEffectivelyVisible())
            ->map(fn ($link): array => [
                'name' => $link->service->publicLabel(),
                'description' => $link->service->webProfile?->short_description ?: '',
            ])
            ->values()
            ->all();

        return [
            'id' => (string) $profile?->id,
            'slug' => $this->resolveProfessionalSlug($professional, $profile),
            'name' => $professional->full_name,
            'title' => $this->resolveProfessionalTitlePrefix($profile),
            'specialization' => $this->resolveProfessionalSpecialization($professional),
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
            'seo' => [
                'title' => $profile?->seo_title,
                'description' => $profile?->seo_description,
                'h1' => $profile?->seo_h1,
                'canonical_url' => $profile?->canonical_url,
                'robots' => $profile?->robots?->value ?? $profile?->robots,
                'og_title' => $profile?->og_title,
                'og_description' => $profile?->og_description,
            ],
        ];
    }

    private function mapEquipeSections(
        Professional $professional,
        ProfessionalPublicProfile $profile,
        Request $request
    ): array {
        $primary = $professional->specializations
            ->sortBy(fn ($item) => [($item->pivot?->is_primary ?? false) ? 0 : 1, $item->pivot?->sort_order ?? PHP_INT_MAX, $item->id])
            ->first();
        $primaryWebProfile = $primary?->webProfile;
        $services = $professional->professionalServices
            ->filter(fn ($link) => $link->is_active
                && $link->is_visible_public
                && $link->service !== null
                && $link->service->isEffectivelyVisible())
            ->map(fn ($link) => [
                'slug' => $link->service->webProfile->public_slug,
                'name' => $link->service->publicLabel(),
                'short_description' => $link->service->webProfile->short_description ?: '',
            ])->values();

        $payloads = [
            'hero' => [
                'avatar_url' => $this->resolveProfessionalImageUrl($professional, $request, $profile),
                'honorific_prefix' => $professional->honorific_prefix,
                'name' => $professional->full_name,
                'primary_specialization' => $primary ? [
                    'id' => $primary->id,
                    'name' => $primary->name,
                    'public_slug' => $primaryWebProfile?->public_slug,
                    'href' => $primaryWebProfile?->is_web_enabled ? '/aree-mediche/'.$primaryWebProfile->public_slug : null,
                ] : null,
                'short_bio' => $profile->short_bio,
                'competencies' => $profile->heroCompetencies->map(fn ($item) => [
                    'id' => $item->id,
                    'title' => $item->title,
                    'icon_key' => $item->icon_key,
                ])->values()->all(),
            ],
            'biography' => ['content' => $profile->bio_content],
            'approach' => [
                'content' => $profile->approach_content,
                'principles' => $profile->approachPrinciples->map(fn ($item) => [
                    'id' => $item->id,
                    'label' => $item->label,
                    'icon_key' => $item->icon_key,
                ])->values()->all(),
            ],
            'competencies' => [
                'items' => $profile->competencies->map(fn ($item) => [
                    'id' => $item->id,
                    'title' => $item->title,
                    'description' => $item->description,
                    'icon_key' => $item->icon_key,
                ])->values()->all(),
            ],
            'career' => [
                'items' => $professional->careerExperiences->map(fn ($item) => [
                    'id' => $item->id,
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
                    'id' => $item->id,
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
            ->sortBy(fn ($section) => [$section->sort_order, $section->id])
            ->map(fn ($section) => [
                'key' => $section->key,
                'order' => (int) $section->sort_order,
                'data' => $section->key === 'services'
                    ? [
                        'title' => $section->title ?: 'Prestazioni',
                        'intro' => $section->content,
                        ...$payloads[$section->key],
                    ]
                    : $payloads[$section->key],
            ])->values()->all();
    }

    private function mapBlogListItem(BlogPost $post): array
    {
        return [
            'slug' => $post->slug,
            'href' => $post->canonicalHref(),
            'title' => $post->title,
            'subtitle' => $post->subtitle ?: $post->excerpt ?: '',
            'category' => $post->category_label ?: 'Blog',
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
        $relatedArticles = $post->relatedArticles
            ->filter(fn (BlogPost $article) => $article->id !== $post->id && $article->isPubliclyAvailable())
            ->pluck('slug')
            ->values()
            ->all();

        $relatedServices = $post->relatedServices
            ->filter(fn (Service $service) => $service->isEffectivelyVisible() && $service->webProfile !== null)
            ->map(fn (Service $service): array => [
                'name' => $service->publicLabel(),
                'slug' => $service->webProfile->public_slug,
                'href' => '/prestazioni/'.$service->webProfile->public_slug,
            ])
            ->values()
            ->all();

        return [
            'slug' => $post->slug,
            'href' => $post->canonicalHref(),
            'title' => $post->title,
            'subtitle' => $post->subtitle ?: $post->excerpt ?: '',
            'category' => $post->category_label ?: 'Blog',
            'excerpt' => $post->excerpt ?: '',
            'content' => $post->intro_text ?: $post->excerpt ?: '',
            'sections' => $post->sections->map(fn (Section $section): array => [
                'type' => $this->resolveBlogSectionType($section),
                'title' => $section->title,
                'content' => $section->content,
                'items' => is_array($section->extra_json['items'] ?? null)
                    ? array_values(array_filter(array_map(
                        fn ($item) => is_string($item) ? $item : null,
                        $section->extra_json['items']
                    )))
                    : [],
            ])->values()->all(),
            'date' => optional($post->published_at)->translatedFormat('j F Y') ?: 'Bozza',
            'author' => $post->author_name ?: 'Redazione Remedic',
            'reviewer' => $post->reviewer_name,
            'featured' => $this->blogPostsHaveFeaturedFlag() ? (bool) ($post->getAttribute('is_featured') ?? false) : false,
            'related_prestazioni' => $relatedServices,
            'related_articles' => $relatedArticles,
            'faq' => $post->faqs->map(fn (FaqItem $faq): array => [
                'question' => $faq->question,
                'answer' => $faq->answer,
            ])->values()->all(),
            'cover_image' => $post->cover_image,
            'content_type' => $post->content_type,
            'editorial_category' => $post->editorial_category,
            'editorial_category_label' => BlogPost::editorialCategories($post->content_type)[$post->editorial_category] ?? null,
            'seo' => [
                'title' => $post->seo_title,
                'description' => $post->seo_description,
                'h1' => $post->seo_h1,
                'canonical_url' => $post->canonical_url,
                'robots' => $post->robots?->value ?? $post->robots,
                'og_title' => $post->og_title,
                'og_description' => $post->og_description,
            ],
        ];
    }

    private function mapPageDetail(Page $page): array
    {
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
            'title' => $page->title,
            'template' => $page->template?->value ?? $page->template,
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
            'seo' => [
                'title' => $page->seo_title,
                'description' => $page->seo_description,
                'h1' => $page->seo_h1,
                'canonical_url' => $page->canonical_url,
                'robots' => $page->robots?->value ?? $page->robots,
                'og_title' => $page->og_title,
                'og_description' => $page->og_description,
                'og_image_url' => $this->resolveMediaPathOrUrl(
                    $page->og_image_path ?: $page->hero_image_path ?: $defaultOgImagePath,
                    request()
                ),
                'twitter_title' => $page->twitter_title,
                'twitter_description' => $page->twitter_description,
                'twitter_image_url' => $this->resolveMediaPathOrUrl(
                    $page->twitter_image_path ?: $page->og_image_path ?: $page->hero_image_path ?: $defaultOgImagePath,
                    request()
                ),
                'author' => $page->meta_author,
                'creator' => $page->meta_creator,
                'keywords' => $page->meta_keywords,
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
            ->published()
            ->whereIn('internal_key', ['why_choose_us', 'plus_health_protocol', 'privacy'])
            ->pluck('slug', 'internal_key');

        return $page->sections
            ->filter(fn (Section $section): bool => PageSectionRegistry::definition((string) $page->internal_key, $section->key) !== null)
            ->map(function (Section $section) use ($targets, $page): array {
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
                    $targetKey = $definition['target_internal_key'];
                    $targetSlug = $targets->get($targetKey);
                    $mapped['action'] = [
                        'label' => $extra['link_label'] ?? null,
                        'target_internal_key' => $targetKey,
                        'href' => $targetSlug ? '/'.$targetSlug : null,
                    ];
                }
                if (isset($definition['actions'])) {
                    $mapped['actions'] = $definition['actions'];
                }
                if (in_array($section->key, ['model_overview', 'three_reasons', 'care_path_overview', 'person_first'], true)) {
                    $mapped['items'] = $extra['items'] ?? [];
                }
                if ($section->key === 'promise') {
                    $mapped['values'] = $extra['values'] ?? [];
                }
                if ($section->key === 'four_pillars') {
                    $mapped['pillars'] = $extra['pillars'] ?? [];
                }
                if ((string) $page->internal_key === PageSectionRegistry::CAREERS_INTERNAL_KEY) {
                    if (in_array($section->key, ['professional_profiles', 'what_we_look_for'], true)) {
                        $mapped = ['key' => $section->key, 'title' => $section->title, 'data' => ['intro' => $section->content, 'subheading' => $extra['subheading'] ?? null, 'items' => $extra['items'] ?? []]];
                    }
                    if ($section->key === 'application') {
                        $privacySlug = $targets->get('privacy');
                        $mapped = ['key' => 'application', 'title' => $section->title, 'data' => ['body' => $section->content, 'action' => ['type' => 'open_application_form'], 'privacy' => ['text' => $extra['privacy_text'] ?? null, 'target_internal_key' => 'privacy', 'href' => $privacySlug ? '/'.$privacySlug : null], 'application_types' => ApplicationType::query()->where('is_active', true)->publicOrder()->get()->map(fn (ApplicationType $type) => ['id' => $type->id, 'name' => $type->name])->all()]];
                    }
                }
                if ((string) $page->internal_key === PageSectionRegistry::CONTACT_INTERNAL_KEY && $section->key === 'location_and_contacts') {
                    $mapped = [
                        'key' => $section->key,
                        'title' => $section->title,
                        'data' => [
                            'intro' => $section->content,
                            'action' => ['type' => 'contact'],
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
                    $mapped['testimonials'] = collect($extra['testimonials'] ?? [])
                        ->filter(fn (array $testimonial): bool => (bool) ($testimonial['is_active'] ?? true))
                        ->sortBy('sort_order')
                        ->values()
                        ->all();
                }

                return $mapped;
            })
            ->values()
            ->all();
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
        return $this->professionalProfilesBaseQuery()
            ->limit($limit)
            ->get()
            ->map(fn (ProfessionalPublicProfile $profile): array => $this->mapProfessionalListItem($profile, $request))
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
            'id' => (string) $profile?->id,
            'slug' => $this->resolveProfessionalSlug($professional, $profile),
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
