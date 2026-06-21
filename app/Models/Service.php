<?php

namespace App\Models;

use App\Enums\RobotsValue;
use App\Models\Concerns\HasSectionsAndFaqs;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Service extends Model
{
    use HasFactory;
    use HasSectionsAndFaqs;

    protected $fillable = [
        'legacy_backend_id',
        'category_id',
        'canonical_name',
        'display_name',
        'importo_prestazione',
        'slug',
        'description',
        'short_description',
        'intro_text',
        'local_intro_text',
        'local_area_notes',
        'preparation_notes',
        'duration_text',
        'price_text',
        'exam_report_time',
        'featured_image_path',
        'social_image_path',
        'default_duration_minutes',
        'is_diagnostic',
        'is_visit',
        'is_featured',
        'is_local_seo_enabled',
        'seo_title',
        'local_seo_title',
        'seo_description',
        'local_seo_description',
        'seo_h1',
        'local_seo_h1',
        'canonical_url',
        'robots',
        'og_title',
        'og_description',
        'is_active',
        'is_web_active',
        'sort_order',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'legacy_backend_id' => 'integer',
            'importo_prestazione' => 'decimal:2',
            'default_duration_minutes' => 'integer',
            'robots' => RobotsValue::class,
            'is_diagnostic' => 'boolean',
            'is_visit' => 'boolean',
            'is_featured' => 'boolean',
            'is_local_seo_enabled' => 'boolean',
            'is_active' => 'boolean',
            'is_web_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'category_id');
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(ServiceAlias::class);
    }

    public function professionalServices(): HasMany
    {
        return $this->hasMany(ProfessionalService::class);
    }

    public function specializations(): BelongsToMany
    {
        return $this->belongsToMany(Specialization::class, 'service_specialization')
            ->withPivot('is_primary', 'sort_order')
            ->withTimestamps();
    }

    public function publicLabel(): string
    {
        $displayName = trim((string) $this->display_name);

        if ($displayName !== '') {
            return $displayName;
        }

        return trim((string) $this->canonical_name);
    }
}
