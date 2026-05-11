<?php

namespace App\Models;

use App\Enums\RobotsValue;
use App\Models\Concerns\HasSectionsAndFaqs;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfessionalPublicProfile extends Model
{
    use HasFactory;
    use HasSectionsAndFaqs;

    protected $fillable = [
        'legacy_backend_id',
        'professional_id',
        'slug',
        'title_prefix',
        'short_bio',
        'registration_number',
        'birth_date',
        'birth_place',
        'profile_image_path',
        'seo_title',
        'seo_description',
        'seo_h1',
        'canonical_url',
        'robots',
        'og_title',
        'og_description',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'legacy_backend_id' => 'integer',
            'birth_date' => 'date',
            'robots' => RobotsValue::class,
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }
}
