<?php

namespace App\Models;

use App\Enums\RobotsValue;
use App\Models\Concerns\HasSectionsAndFaqs;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Specialization extends Model
{
    use HasFactory;
    use HasSectionsAndFaqs;

    protected $fillable = [
        'legacy_backend_id',
        'name',
        'slug',
        'color_hex',
        'icon_path',
        'short_description',
        'intro_text',
        'local_intro_text',
        'local_area_notes',
        'seo_title',
        'local_seo_title',
        'seo_description',
        'local_seo_description',
        'seo_h1',
        'local_seo_h1',
        'is_local_seo_enabled',
        'canonical_url',
        'robots',
        'og_title',
        'og_description',
        'is_active',
        'is_web_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'legacy_backend_id' => 'integer',
            'robots' => RobotsValue::class,
            'is_local_seo_enabled' => 'boolean',
            'is_active' => 'boolean',
            'is_web_active' => 'boolean',
            'color_hex' => 'string',
            'icon_path' => 'string',
            'sort_order' => 'integer',
        ];
    }

    public function professionals(): BelongsToMany
    {
        return $this->belongsToMany(Professional::class, 'professional_specialization')
            ->withPivot(['is_primary', 'sort_order'])
            ->withTimestamps();
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'service_specialization')
            ->withPivot(['is_primary', 'sort_order'])
            ->withTimestamps();
    }
}
