<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Models\BlogPost;
use App\Models\FaqItem;
use App\Models\Page;
use App\Models\Professional;
use App\Models\ProfessionalPublicProfile;
use App\Models\Section;
use App\Models\Service;
use App\Models\SiteSetting;
use App\Models\Specialization;
use App\Support\Media\PublicMediaUrl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SiteController extends Controller
{
    public function settings(Request $request): JsonResponse
    {
        $settings = SiteSetting::singleton();
        $specializations = $this->specializationsBaseQuery()
            ->limit(7)
            ->get();

        return response()->json([
            'data' => [
                'settings' => $this->mapSiteSettings($settings),
                'navigation' => [
                    ['label' => 'Chi siamo', 'href' => '/chi-siamo'],
                    ['label' => 'Specializzazioni', 'href' => '/specializzazioni'],
                    ['label' => 'Equipe', 'href' => '/equipe'],
                    ['label' => 'Blog', 'href' => '/blog'],
                    ['label' => 'Contatti', 'href' => '/contatti'],
                ],
                'footer_navigation' => [
                    ['label' => 'Chi siamo', 'href' => '/chi-siamo'],
                    ['label' => 'Specializzazioni', 'href' => '/specializzazioni'],
                    ['label' => 'Prestazioni', 'href' => '/prestazioni'],
                    ['label' => 'Equipe', 'href' => '/equipe'],
                    ['label' => 'Medicina estetica', 'href' => '/medicina-estetica'],
                    ['label' => 'Blog', 'href' => '/blog'],
                    ['label' => 'Contatti', 'href' => '/contatti'],
                ],
                'footer_specializations' => $specializations->map(fn (Specialization $specialization): array => [
                    'label' => $specialization->name,
                    'href' => '/specializzazioni/'.$specialization->slug,
                ])->values()->all(),
            ],
        ]);
    }

    public function home(Request $request): JsonResponse
    {
        $specializations = $this->specializationsBaseQuery()->limit(8)->get();
        $services = $this->servicesBaseQuery()
            ->where('is_featured', true)
            ->limit(7)
            ->get();

        if ($services->isEmpty()) {
            $services = $this->servicesBaseQuery()->limit(7)->get();
        }

        $professionals = $this->professionalProfilesBaseQuery()->limit(4)->get();
        $blogPosts = $this->blogPostsBaseQuery()
            ->limit(4)
            ->get();

        return response()->json([
            'data' => [
                'specializations' => $specializations->map(fn (Specialization $specialization): array => $this->mapSpecializationListItem($specialization))->values()->all(),
                'services' => $services->map(fn (Service $service): array => $this->mapServiceListItem($service))->values()->all(),
                'professionals' => $professionals->map(fn (ProfessionalPublicProfile $profile): array => $this->mapProfessionalListItem($profile, $request))->values()->all(),
                'blog_posts' => $blogPosts->map(fn (BlogPost $post): array => $this->mapBlogListItem($post))->values()->all(),
            ],
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));

        if (mb_strlen($query) < 2) {
            return response()->json(['data' => ['results' => []]]);
        }

        $specializations = $this->specializationsBaseQuery()
            ->where('name', 'like', "%{$query}%")
            ->limit(6)
            ->get()
            ->map(fn (Specialization $specialization): array => [
                'type' => 'specialization',
                'title' => $specialization->name,
                'subtitle' => null,
                'href' => '/specializzazioni/'.$specialization->slug,
            ]);

        $services = $this->servicesBaseQuery()
            ->where(function (Builder $builder) use ($query): void {
                $builder
                    ->where('display_name', 'like', "%{$query}%")
                    ->orWhere('canonical_name', 'like', "%{$query}%")
                    ->orWhere('slug', 'like', "%{$query}%");
            })
            ->limit(6)
            ->get()
            ->map(fn (Service $service): array => [
                'type' => 'service',
                'title' => $service->publicLabel(),
                'subtitle' => null,
                'href' => '/prestazioni/'.$service->slug,
            ]);

        $professionals = $this->professionalProfilesBaseQuery()
            ->where(function (Builder $builder) use ($query): void {
                $builder
                    ->where('slug', 'like', "%{$query}%")
                    ->orWhere('title_prefix', 'like', "%{$query}%")
                    ->orWhereHas('professional', function (Builder $professionalQuery) use ($query): void {
                        $professionalQuery->where('full_name', 'like', "%{$query}%");
                    })
                    ->orWhereHas('professional.specializations', function (Builder $specializationQuery) use ($query): void {
                        $specializationQuery->where('name', 'like', "%{$query}%");
                    });
            })
            ->limit(6)
            ->get()
            ->map(fn (ProfessionalPublicProfile $profile): array => [
                'type' => 'doctor',
                'title' => trim(($profile->title_prefix ? $profile->title_prefix.' ' : '').$profile->professional->full_name),
                'subtitle' => $profile->professional->specializations->pluck('name')->filter()->implode(', '),
                'href' => '/equipe/'.$profile->slug,
            ]);

        return response()->json([
            'data' => [
                'results' => $specializations
                    ->concat($services)
                    ->concat($professionals)
                    ->values()
                    ->all(),
            ],
        ]);
    }

    public function specializations(Request $request): JsonResponse
    {
        $limit = $this->resolveLimit($request, 50);

        $specializations = $this->specializationsBaseQuery()
            ->withCount(['services', 'professionals'])
            ->limit($limit)
            ->get();

        return response()->json([
            'data' => $specializations->map(fn (Specialization $specialization): array => $this->mapSpecializationListItem($specialization))->values()->all(),
        ]);
    }

    public function specialization(Request $request, string $slug): JsonResponse
    {
        $specialization = $this->specializationsBaseQuery()
            ->withCount(['services', 'professionals'])
            ->where('slug', $slug)
            ->firstOrFail();

        return response()->json([
            'data' => $this->mapSpecializationDetail($specialization, $request),
        ]);
    }

    public function services(Request $request): JsonResponse
    {
        $limit = $this->resolveLimit($request, 100);
        $query = $this->servicesBaseQuery();

        if ($request->boolean('featured')) {
            $query->where('is_featured', true);
        }

        $services = $query->limit($limit)->get();

        return response()->json([
            'data' => $services->map(fn (Service $service): array => $this->mapServiceListItem($service))->values()->all(),
        ]);
    }

    public function service(Request $request, string $slug): JsonResponse
    {
        $service = $this->servicesBaseQuery()
            ->where('slug', $slug)
            ->firstOrFail();

        return response()->json([
            'data' => $this->mapServiceDetail($service, $request),
        ]);
    }

    public function professionals(Request $request): JsonResponse
    {
        $limit = $this->resolveLimit($request, 100);
        $profiles = $this->professionalProfilesBaseQuery()
            ->limit($limit)
            ->get();

        return response()->json([
            'data' => $profiles->map(fn (ProfessionalPublicProfile $profile): array => $this->mapProfessionalListItem($profile, $request))->values()->all(),
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

        if ($request->boolean('featured')) {
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

    private function resolveLimit(Request $request, int $default): int
    {
        return max(1, min(100, $request->integer('limit', $default)));
    }

    private function specializationsBaseQuery(): Builder
    {
        return Specialization::query()
            ->with([
                'sections' => fn ($query) => $query->active()->ordered(),
                'faqs' => fn ($query) => $query->active()->ordered(),
                'services' => fn ($query) => $query
                    ->where('services.is_active', true)
                    ->orderBy('service_specialization.sort_order')
                    ->orderBy('display_name'),
                'services.specializations' => fn ($query) => $query->where('specializations.is_active', true)->orderBy('sort_order')->orderBy('name'),
                'professionals' => fn ($query) => $query
                    ->where('professionals.is_active', true)
                    ->orderBy('professional_specialization.sort_order')
                    ->orderBy('full_name'),
                'professionals.publicProfile' => fn ($query) => $query->where('is_active', true),
                'professionals.specializations' => fn ($query) => $query->where('specializations.is_active', true)->orderBy('sort_order')->orderBy('name'),
            ])
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name');
    }

    private function servicesBaseQuery(): Builder
    {
        return Service::query()
            ->with([
                'category',
                'sections' => fn ($query) => $query->active()->ordered(),
                'faqs' => fn ($query) => $query->active()->ordered(),
                'specializations' => fn ($query) => $query->where('specializations.is_active', true)->orderBy('service_specialization.sort_order')->orderBy('name'),
                'professionalServices' => fn ($query) => $query
                    ->where('is_active', true)
                    ->where('is_visible_public', true)
                    ->orderBy('public_sort_order')
                    ->orderBy('id'),
                'professionalServices.professional' => fn ($query) => $query->where('is_active', true),
                'professionalServices.professional.publicProfile' => fn ($query) => $query->where('is_active', true),
                'professionalServices.professional.specializations' => fn ($query) => $query->where('specializations.is_active', true)->orderBy('sort_order')->orderBy('name'),
            ])
            ->where('is_active', true)
            ->orderByDesc('is_featured')
            ->orderBy('sort_order')
            ->orderBy('display_name');
    }

    private function professionalProfilesBaseQuery(): Builder
    {
        return ProfessionalPublicProfile::query()
            ->with([
                'sections' => fn ($query) => $query->active()->ordered(),
                'faqs' => fn ($query) => $query->active()->ordered(),
                'professional' => fn ($query) => $query->where('is_active', true),
                'professional.specializations' => fn ($query) => $query->where('specializations.is_active', true)->orderBy('professional_specialization.sort_order')->orderBy('name'),
                'professional.professionalServices' => fn ($query) => $query
                    ->where('is_active', true)
                    ->where('is_visible_public', true)
                    ->orderBy('public_sort_order')
                    ->orderBy('id'),
                'professional.professionalServices.service' => fn ($query) => $query->where('is_active', true)->orderBy('display_name'),
                'professional.degrees',
                'professional.academicSpecializations',
                'professional.boardRegistrations',
            ])
            ->where('is_active', true)
            ->whereHas('professional', fn (Builder $query) => $query->where('is_active', true))
            ->orderBy('sort_order')
            ->orderBy('slug');
    }

    private function blogPostsBaseQuery(): Builder
    {
        return BlogPost::query()
            ->with([
                'sections' => fn ($query) => $query->active()->ordered(),
                'faqs' => fn ($query) => $query->active()->ordered(),
            ])
            ->where('is_active', true)
            ->where(function (Builder $query): void {
                $query->whereNull('published_at')->orWhere('published_at', '<=', now());
            })
            ->orderByDesc('is_featured')
            ->orderByDesc('published_at')
            ->orderByDesc('id');
    }

    private function mapSiteSettings(SiteSetting $settings): array
    {
        return [
            'site_name' => $settings->site_name ?: $settings->brand_name ?: 'Remedic',
            'brand_name' => $settings->brand_name ?: $settings->site_name ?: 'Remedic',
            'site_url' => $settings->site_url,
            'default_meta_title' => $settings->default_meta_title,
            'default_meta_description' => $settings->default_meta_description,
            'clinic_name' => $settings->clinic_name,
            'clinic_phone' => $settings->clinic_phone,
            'clinic_email' => $settings->clinic_email,
            'clinic_address' => $settings->clinic_address,
            'clinic_city' => $settings->clinic_city,
            'clinic_postal_code' => $settings->clinic_postal_code,
            'clinic_country' => $settings->clinic_country,
            'maps_url' => $settings->maps_url ?: $settings->google_maps_url,
            'latitude' => $settings->latitude,
            'longitude' => $settings->longitude,
            'facebook_url' => $settings->facebook_url,
            'instagram_url' => $settings->instagram_url,
            'linkedin_url' => $settings->linkedin_url,
            'whatsapp_number' => $settings->whatsapp_number,
            'opening_hours' => is_array($settings->opening_hours) ? $settings->opening_hours : [],
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

    private function mapSpecializationListItem(Specialization $specialization): array
    {
        return [
            'slug' => $specialization->slug,
            'name' => $specialization->name,
            'description' => $specialization->short_description ?: $specialization->intro_text ?: '',
            'short_description' => $specialization->short_description,
            'services_count' => $specialization->services_count ?? $specialization->services->count(),
            'professionals_count' => $specialization->professionals_count ?? $specialization->professionals->filter(fn (Professional $professional) => $professional->publicProfile !== null)->count(),
            'is_active' => (bool) $specialization->is_active,
        ];
    }

    private function mapSpecializationDetail(Specialization $specialization, Request $request): array
    {
        $services = $specialization->services->filter(fn (Service $service) => (bool) $service->is_active)->values();
        $professionals = $specialization->professionals
            ->filter(fn (Professional $professional) => $professional->publicProfile !== null)
            ->values();

        $introBlocks = $this->buildSpecializationIntroBlocks($specialization);
        $mainService = $services->first();

        return [
            'slug' => $specialization->slug,
            'name' => $specialization->name,
            'description' => $specialization->intro_text ?: $specialization->short_description ?: '',
            'short_description' => $specialization->short_description ?: '',
            'procedures' => $services->count(),
            'intro' => $introBlocks,
            'main_performance' => $mainService ? [
                'slug' => $mainService->slug,
                'name' => $mainService->publicLabel(),
                'description' => $mainService->short_description ?: $mainService->description ?: '',
            ] : null,
            'other_performances' => $services->skip(1)->map(fn (Service $service): array => [
                'slug' => $service->slug,
                'name' => $service->publicLabel(),
                'description' => $service->short_description ?: $service->description ?: '',
            ])->values()->all(),
            'doctors' => $professionals->map(function (Professional $professional) use ($request): array {
                /** @var ProfessionalPublicProfile|null $profile */
                $profile = $professional->publicProfile;

                return [
                    'slug' => $profile?->slug,
                    'name' => trim(($profile?->title_prefix ? $profile->title_prefix.' ' : '').$professional->full_name),
                    'specialization' => $professional->specializations->pluck('name')->first() ?: 'Specialista',
                    'description' => $profile?->short_bio ?: '',
                    'image_url' => $profile?->profile_image_path
                        ? PublicMediaUrl::fromPublicDisk($profile->profile_image_path, $request)
                        : PublicMediaUrl::fromPublicDisk($professional->avatar_path, $request),
                    'main_procedures' => $professional->professionalServices
                        ->filter(fn ($link) => $link->service !== null && $link->service->is_active)
                        ->take(3)
                        ->map(fn ($link) => $link->service->publicLabel())
                        ->values()
                        ->all(),
                ];
            })->values()->all(),
            'faq' => $specialization->faqs->map(fn (FaqItem $faq): array => [
                'question' => $faq->question,
                'answer' => $faq->answer,
            ])->values()->all(),
            'seo' => [
                'title' => $specialization->seo_title,
                'description' => $specialization->seo_description,
                'h1' => $specialization->seo_h1,
                'canonical_url' => $specialization->canonical_url,
            ],
        ];
    }

    private function buildSpecializationIntroBlocks(Specialization $specialization): array
    {
        $whatIs = $this->findSectionByKeys($specialization->sections, ['what_is', 'whatis', 'overview', 'cosa_fa']);
        $when = $this->findSectionByKeys($specialization->sections, ['when', 'when_to_book', 'quando_prenotare']);
        $remedy = $this->findSectionByKeys($specialization->sections, ['remedy', 'why_remedic', 'come_ti_accompagna_remedic']);

        return [
            'what_is' => [
                'title' => $whatIs?->title ?: 'Di cosa si occupa '.$specialization->name,
                'content' => $whatIs?->content ?: ($specialization->intro_text ?: $specialization->short_description ?: ''),
            ],
            'when' => [
                'title' => $when?->title ?: 'Quando può essere utile',
                'content' => $when?->content ?: ($specialization->local_intro_text ?: $specialization->short_description ?: ''),
            ],
            'remedy' => [
                'title' => $remedy?->title ?: 'Come ti accompagna Remedic',
                'content' => $remedy?->content ?: ($specialization->local_area_notes ?: $specialization->short_description ?: ''),
            ],
        ];
    }

    private function mapServiceListItem(Service $service): array
    {
        $primarySpecialization = $service->specializations->first();

        return [
            'slug' => $service->slug,
            'name' => $service->publicLabel(),
            'specialization' => $primarySpecialization?->name ?: 'Prestazioni',
            'category' => $this->resolveServiceCategoryId($service),
            'short_description' => $service->short_description ?: $service->description ?: '',
            'description' => $service->description ?: $service->short_description ?: '',
            'featured' => (bool) $service->is_featured,
        ];
    }

    private function mapServiceDetail(Service $service, Request $request): array
    {
        $professionals = $service->professionalServices
            ->filter(fn ($link) => $link->professional !== null && $link->professional->publicProfile !== null)
            ->values();

        $relatedServices = Service::query()
            ->where('is_active', true)
            ->whereKeyNot($service->id)
            ->whereHas('specializations', fn (Builder $query) => $query->whereIn('specializations.id', $service->specializations->pluck('id')->all()))
            ->orderByDesc('is_featured')
            ->orderBy('display_name')
            ->limit(5)
            ->get();

        $sections = $service->sections->map(fn (Section $section): array => [
            'title' => $section->title ?: ucfirst(str_replace('_', ' ', $section->key)),
            'content' => $section->content ?: '',
        ])->values()->all();

        if ($sections === []) {
            $sections = array_values(array_filter([
                $service->description ? ['title' => 'A cosa serve', 'content' => $service->description] : null,
                $service->intro_text ? ['title' => 'Come si svolge', 'content' => $service->intro_text] : null,
                $service->preparation_notes ? ['title' => 'Preparazione', 'content' => $service->preparation_notes] : null,
            ]));
        }

        return [
            'slug' => $service->slug,
            'name' => $service->publicLabel(),
            'category' => $this->resolveServiceCategoryId($service),
            'specialization' => $service->specializations->first()?->name ?: 'Prestazioni',
            'short_description' => $service->short_description ?: $service->description ?: '',
            'description' => $service->description ?: $service->short_description ?: '',
            'duration' => $service->duration_text ?: ($service->default_duration_minutes ? $service->default_duration_minutes.' min' : 'Da definire'),
            'preparation' => $service->preparation_notes ?: 'Indicazioni fornite in fase di prenotazione.',
            'modalita' => 'In sede',
            'sections' => $sections,
            'doctors' => $professionals->map(function ($link) use ($request): array {
                $professional = $link->professional;
                $profile = $professional->publicProfile;

                return [
                    'slug' => $profile->slug,
                    'name' => trim(($profile->title_prefix ? $profile->title_prefix.' ' : '').$professional->full_name),
                    'specialization' => $professional->specializations->pluck('name')->first() ?: 'Specialista',
                    'image_url' => $profile->profile_image_path
                        ? PublicMediaUrl::fromPublicDisk($profile->profile_image_path, $request)
                        : PublicMediaUrl::fromPublicDisk($professional->avatar_path, $request),
                ];
            })->values()->all(),
            'related_prestazioni' => $relatedServices->map(fn (Service $related): array => [
                'slug' => $related->slug,
                'name' => $related->publicLabel(),
            ])->values()->all(),
            'faq' => $service->faqs->map(fn (FaqItem $faq): array => [
                'question' => $faq->question,
                'answer' => $faq->answer,
            ])->values()->all(),
            'featured' => (bool) $service->is_featured,
        ];
    }

    private function mapProfessionalListItem(ProfessionalPublicProfile $profile, Request $request): array
    {
        $professional = $profile->professional;

        return [
            'id' => (string) $profile->id,
            'slug' => $profile->slug,
            'name' => $professional->full_name,
            'title' => $profile->title_prefix ?: 'Dott.',
            'specialization' => $professional->specializations->pluck('name')->first() ?: 'Specialista',
            'short_bio' => $profile->short_bio ?: '',
            'image_url' => $profile->profile_image_path
                ? PublicMediaUrl::fromPublicDisk($profile->profile_image_path, $request)
                : PublicMediaUrl::fromPublicDisk($professional->avatar_path, $request),
            'featured' => false,
            'available_services' => $professional->professionalServices
                ->filter(fn ($link) => $link->service !== null && $link->service->is_active)
                ->take(5)
                ->map(fn ($link) => $link->service->publicLabel())
                ->values()
                ->all(),
        ];
    }

    private function mapProfessionalDetail(ProfessionalPublicProfile $profile, Request $request): array
    {
        $professional = $profile->professional;
        $fullBioSection = $this->findSectionByKeys($profile->sections, ['full_bio', 'bio', 'biography']);
        $profileSection = $this->findSectionByKeys($profile->sections, ['professional_profile', 'profile']);
        $experienceSection = $this->findSectionByKeys($profile->sections, ['clinical_experience', 'experience']);
        $approachSection = $this->findSectionByKeys($profile->sections, ['patient_approach', 'approach']);
        $areasSection = $this->findSectionByKeys($profile->sections, ['areas_of_interest', 'interests']);

        $services = $professional->professionalServices
            ->filter(fn ($link) => $link->service !== null && $link->service->is_active)
            ->map(fn ($link): array => [
                'name' => $link->service->publicLabel(),
                'description' => $link->service->short_description ?: $link->service->description ?: '',
            ])
            ->values()
            ->all();

        $areasOfInterest = [];
        if (is_array($areasSection?->extra_json['items'] ?? null)) {
            $areasOfInterest = array_values(array_filter(array_map(
                fn ($item) => is_string($item) ? $item : null,
                $areasSection->extra_json['items']
            )));
        }

        if ($areasOfInterest === []) {
            $areasOfInterest = $professional->specializations->pluck('name')->values()->all();
        }

        $publications = [];
        $publicationsSection = $this->findSectionByKeys($profile->sections, ['publications', 'articles']);
        if (is_array($publicationsSection?->extra_json['items'] ?? null)) {
            $publications = array_values(array_filter(array_map(function ($item): ?array {
                if (! is_array($item)) {
                    return null;
                }

                return [
                    'title' => (string) ($item['title'] ?? ''),
                    'year' => isset($item['year']) ? (int) $item['year'] : null,
                    'journal' => (string) ($item['journal'] ?? ''),
                ];
            }, $publicationsSection->extra_json['items'])));
        }

        return [
            'id' => (string) $profile->id,
            'slug' => $profile->slug,
            'name' => $professional->full_name,
            'title' => $profile->title_prefix ?: 'Dott.',
            'specialization' => $professional->specializations->pluck('name')->first() ?: 'Specialista',
            'short_bio' => $profile->short_bio ?: '',
            'full_bio' => $fullBioSection?->content ?: ($profile->short_bio ?: ''),
            'professional_profile' => $profileSection?->content ?: ($profile->short_bio ?: ''),
            'education' => $professional->degrees->map(fn ($degree) => $degree->title)->values()->all(),
            'clinical_experience' => $experienceSection?->content ?: '',
            'patient_approach' => $approachSection?->content ?: '',
            'areas_of_interest' => $areasOfInterest,
            'services' => $services,
            'publications' => $publications,
            'available_services' => array_map(fn (array $service): string => $service['name'], $services),
            'faq_items' => $profile->faqs->map(fn (FaqItem $faq): array => [
                'question' => $faq->question,
                'answer' => $faq->answer,
            ])->values()->all(),
            'image_url' => $profile->profile_image_path
                ? PublicMediaUrl::fromPublicDisk($profile->profile_image_path, $request)
                : PublicMediaUrl::fromPublicDisk($professional->avatar_path, $request),
        ];
    }

    private function mapBlogListItem(BlogPost $post): array
    {
        return [
            'slug' => $post->slug,
            'title' => $post->title,
            'subtitle' => $post->subtitle ?: $post->excerpt ?: '',
            'category' => $post->category_label ?: 'Blog',
            'excerpt' => $post->excerpt ?: '',
            'date' => optional($post->published_at)->translatedFormat('j F Y') ?: 'Bozza',
            'author' => $post->author_name ?: 'Redazione Remedic',
            'reviewer' => $post->reviewer_name,
            'featured' => (bool) $post->is_featured,
            'cover_image' => $post->cover_image,
        ];
    }

    private function mapBlogDetail(BlogPost $post): array
    {
        $relatedArticles = [];
        if (is_array($post->related_article_slugs)) {
            $relatedArticles = array_values(array_filter(array_map(
                fn ($slug) => is_string($slug) ? $slug : null,
                $post->related_article_slugs
            )));
        }

        $relatedServices = [];
        if (is_array($post->related_service_slugs)) {
            $relatedServices = Service::query()
                ->where('is_active', true)
                ->whereIn('slug', $post->related_service_slugs)
                ->orderBy('display_name')
                ->get()
                ->map(fn (Service $service): array => [
                    'name' => $service->publicLabel(),
                    'slug' => $service->slug,
                ])
                ->values()
                ->all();
        }

        return [
            'slug' => $post->slug,
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
            'featured' => (bool) $post->is_featured,
            'related_prestazioni' => $relatedServices,
            'related_articles' => $relatedArticles,
            'faq' => $post->faqs->map(fn (FaqItem $faq): array => [
                'question' => $faq->question,
                'answer' => $faq->answer,
            ])->values()->all(),
            'cover_image' => $post->cover_image,
        ];
    }

    private function resolveServiceCategoryId(Service $service): string
    {
        if ($service->is_diagnostic) {
            return 'diagnostica';
        }

        if ($service->is_visit) {
            return 'visite';
        }

        return 'visite';
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
     * @param iterable<int, Section> $sections
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
