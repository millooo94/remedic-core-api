<?php

namespace App\Services;

use App\Models\BlogPost;
use App\Models\FaqItem;
use App\Models\Page;
use App\Models\ProfessionalPublicProfile;
use App\Models\Redirect;
use App\Models\Section;
use App\Models\Service;
use App\Models\SiteSetting;
use App\Models\Specialization;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class LegacyWebContentImportService
{
    public const DEFAULT_GROUPS = [
        'pages',
        'blog_posts',
        'site_settings',
        'redirects',
        'sections',
        'faq_items',
    ];

    public function import(array $groups = self::DEFAULT_GROUPS, bool $dryRun = true): array
    {
        $connection = DB::connection('legacy_backend');

        $normalizedGroups = $this->normalizeGroups($groups);
        $report = [
            'dry_run' => $dryRun,
            'groups' => $normalizedGroups,
            'items' => [],
        ];

        foreach ($normalizedGroups as $group) {
            $report['items'][$group] = $this->importGroup($connection, $group, $dryRun);
        }

        return $report;
    }

    /**
     * @param  list<string>  $groups
     * @return list<string>
     */
    protected function normalizeGroups(array $groups): array
    {
        $groups = $groups === [] ? self::DEFAULT_GROUPS : $groups;
        $allowed = self::DEFAULT_GROUPS;

        return array_values(array_intersect($allowed, array_unique(array_map(
            static fn (string $group): string => trim($group),
            $groups,
        ))));
    }

    protected function importGroup(ConnectionInterface $connection, string $group, bool $dryRun): array
    {
        return match ($group) {
            'pages' => $this->importPages($connection, $dryRun),
            'blog_posts' => $this->importBlogPosts($connection, $dryRun),
            'site_settings' => $this->importSiteSettings($connection, $dryRun),
            'redirects' => $this->importRedirects($connection, $dryRun),
            'sections' => $this->importSections($connection, $dryRun),
            'faq_items' => $this->importFaqItems($connection, $dryRun),
            default => $this->reportRowCounts([], 0, 0, 0, ["Gruppo non supportato: {$group}"]),
        };
    }

    protected function importPages(ConnectionInterface $connection, bool $dryRun): array
    {
        $rows = $this->fetchRows($connection, 'pages');
        $created = 0;
        $updated = 0;

        foreach ($rows as $row) {
            $payload = Arr::only($row, [
                'title',
                'slug',
                'template',
                'excerpt',
                'intro_text',
                'seo_title',
                'seo_description',
                'seo_h1',
                'canonical_url',
                'robots',
                'og_title',
                'og_description',
                'is_active',
                'published_at',
                'created_at',
                'updated_at',
            ]);

            $target = Page::query()->where('legacy_backend_id', $row['id'])->first();

            if ($target === null) {
                $created++;
            } else {
                $updated++;
            }

            if (! $dryRun) {
                Page::query()->updateOrCreate(
                    ['legacy_backend_id' => $row['id']],
                    $payload,
                );
            }
        }

        return $this->reportRowCounts($rows, $created, $updated);
    }

    protected function importBlogPosts(ConnectionInterface $connection, bool $dryRun): array
    {
        $rows = $this->fetchRows($connection, 'blog_posts');
        $created = 0;
        $updated = 0;

        foreach ($rows as $row) {
            $payload = Arr::only($row, [
                'title',
                'slug',
                'excerpt',
                'cover_image',
                'seo_title',
                'seo_description',
                'seo_h1',
                'canonical_url',
                'robots',
                'og_title',
                'og_description',
                'is_active',
                'published_at',
                'created_at',
                'updated_at',
            ]);

            $target = BlogPost::query()->where('legacy_backend_id', $row['id'])->first();

            if ($target === null) {
                $created++;
            } else {
                $updated++;
            }

            if (! $dryRun) {
                BlogPost::query()->updateOrCreate(
                    ['legacy_backend_id' => $row['id']],
                    $payload,
                );
            }
        }

        return $this->reportRowCounts($rows, $created, $updated);
    }

    protected function importSiteSettings(ConnectionInterface $connection, bool $dryRun): array
    {
        $rows = $this->fetchRows($connection, 'site_settings');
        $created = 0;
        $updated = 0;

        foreach ($rows as $row) {
            $payload = Arr::only($row, [
                'site_name',
                'site_url',
                'brand_name',
                'default_meta_title',
                'default_meta_description',
                'clinic_name',
                'clinic_phone',
                'clinic_email',
                'clinic_address',
                'clinic_city',
                'primary_city',
                'primary_area',
                'served_areas',
                'province_or_area_served',
                'clinic_region',
                'clinic_postal_code',
                'clinic_country',
                'google_maps_url',
                'maps_url',
                'latitude',
                'longitude',
                'area_served_text',
                'default_locality_phrase',
                'facebook_url',
                'instagram_url',
                'linkedin_url',
                'whatsapp_number',
                'logo_path',
                'default_og_image_path',
                'opening_hours',
                'vat_number',
                'legal_company_name',
                'business_type',
                'cmp_enabled',
                'cmp_banner_enabled',
                'cmp_consent_cookie_name',
                'cmp_consent_cookie_ttl_days',
                'cmp_consent_storage_strategy',
                'cmp_show_reject_all_button',
                'cmp_show_accept_all_button',
                'cmp_show_manage_preferences_button',
                'cmp_respect_dnt_flag',
                'cmp_consent_mode_enabled',
                'cmp_auto_reprompt_on_policy_change',
                'cmp_default_locale',
                'privacy_email',
                'created_at',
                'updated_at',
            ]);

            $target = SiteSetting::query()->where('legacy_backend_id', $row['id'])->first()
                ?? SiteSetting::query()->find(1);

            if ($target === null) {
                $created++;
            } else {
                $updated++;
            }

            if (! $dryRun) {
                $siteSetting = $target ?? new SiteSetting(['id' => 1]);
                $siteSetting->fill($payload);
                $siteSetting->legacy_backend_id = $row['id'];
                $siteSetting->save();
            }
        }

        return $this->reportRowCounts($rows, $created, $updated);
    }

    protected function importRedirects(ConnectionInterface $connection, bool $dryRun): array
    {
        $rows = $this->fetchRows($connection, 'redirects');
        $created = 0;
        $updated = 0;

        foreach ($rows as $row) {
            $payload = Arr::only($row, [
                'from_path',
                'to_path',
                'http_code',
                'is_active',
                'created_at',
                'updated_at',
            ]);

            $target = Redirect::query()->where('legacy_backend_id', $row['id'])->first();

            if ($target === null) {
                $created++;
            } else {
                $updated++;
            }

            if (! $dryRun) {
                Redirect::query()->updateOrCreate(
                    ['legacy_backend_id' => $row['id']],
                    $payload,
                );
            }
        }

        return $this->reportRowCounts($rows, $created, $updated);
    }

    protected function importSections(ConnectionInterface $connection, bool $dryRun): array
    {
        $rows = $this->fetchRows($connection, 'sections');
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $warnings = [];

        foreach ($rows as $row) {
            [$type, $id] = $this->mapMorphTarget(
                (string) $row['sectionable_type'],
                (int) $row['sectionable_id'],
            );

            if ($type === null || $id === null) {
                $skipped++;
                $warnings[] = "Section legacy #{$row['id']} skipped: parent non trovato per {$row['sectionable_type']}#{$row['sectionable_id']}.";

                continue;
            }

            $payload = [
                'sectionable_type' => $type,
                'sectionable_id' => $id,
                'key' => $row['key'],
                'title' => $row['title'],
                'subtitle' => $row['subtitle'],
                'content' => $row['content'],
                'extra_json' => $this->decodeJsonField($row['extra_json'] ?? null),
                'sort_order' => $row['sort_order'],
                'is_active' => $row['is_active'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
            ];

            $target = Section::query()->where('legacy_backend_id', $row['id'])->first();

            if ($target === null) {
                $created++;
            } else {
                $updated++;
            }

            if (! $dryRun) {
                Section::query()->updateOrCreate(
                    ['legacy_backend_id' => $row['id']],
                    $payload,
                );
            }
        }

        return $this->reportRowCounts($rows, $created, $updated, $skipped, $warnings);
    }

    protected function importFaqItems(ConnectionInterface $connection, bool $dryRun): array
    {
        $rows = $this->fetchRows($connection, 'faq_items');
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $warnings = [];

        foreach ($rows as $row) {
            [$type, $id] = $this->mapMorphTarget(
                (string) $row['faqable_type'],
                (int) $row['faqable_id'],
            );

            if ($type === null || $id === null) {
                $skipped++;
                $warnings[] = "Faq legacy #{$row['id']} skipped: parent non trovato per {$row['faqable_type']}#{$row['faqable_id']}.";

                continue;
            }

            $payload = [
                'faqable_type' => $type,
                'faqable_id' => $id,
                'question' => $row['question'],
                'answer' => $row['answer'],
                'sort_order' => $row['sort_order'],
                'is_active' => $row['is_active'],
                'is_structured_data' => $row['is_structured_data'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
            ];

            $target = FaqItem::query()->where('legacy_backend_id', $row['id'])->first();

            if ($target === null) {
                $created++;
            } else {
                $updated++;
            }

            if (! $dryRun) {
                FaqItem::query()->updateOrCreate(
                    ['legacy_backend_id' => $row['id']],
                    $payload,
                );
            }
        }

        return $this->reportRowCounts($rows, $created, $updated, $skipped, $warnings);
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function fetchRows(ConnectionInterface $connection, string $table): array
    {
        if (! $connection->getSchemaBuilder()->hasTable($table)) {
            return [];
        }

        return $connection->table($table)
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): array => (array) $row)
            ->all();
    }

    /**
     * @return array{0: class-string|null, 1: int|null}
     */
    protected function mapMorphTarget(string $legacyType, int $legacyId): array
    {
        return match ($legacyType) {
            'App\\Models\\Page' => $this->resolveLegacyTarget(Page::class, $legacyId),
            'App\\Models\\BlogPost' => $this->resolveLegacyTarget(BlogPost::class, $legacyId),
            'App\\Models\\Service' => $this->resolveLegacyTarget(Service::class, $legacyId),
            'App\\Models\\Specialization' => $this->resolveLegacyTarget(Specialization::class, $legacyId),
            'App\\Models\\Doctor' => $this->resolveLegacyTarget(ProfessionalPublicProfile::class, $legacyId),
            default => [null, null],
        };
    }

    /**
     * @param  class-string  $modelClass
     * @return array{0: class-string|null, 1: int|null}
     */
    protected function resolveLegacyTarget(string $modelClass, int $legacyId): array
    {
        $model = $modelClass::query()
            ->where('legacy_backend_id', $legacyId)
            ->first();

        if ($model === null) {
            return [null, null];
        }

        return [$modelClass, (int) $model->getKey()];
    }

    protected function decodeJsonField(mixed $value): mixed
    {
        if (! is_string($value) || trim($value) === '') {
            return $value;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $warnings
     * @return array{source_records:int,created:int,updated:int,skipped:int,warnings:list<string>}
     */
    protected function reportRowCounts(array $rows, int $created, int $updated, int $skipped = 0, array $warnings = []): array
    {
        return [
            'source_records' => count($rows),
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'warnings' => $warnings,
        ];
    }
}
