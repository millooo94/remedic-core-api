<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfessionalCareerExperience extends Model
{
    use HasFactory;

    protected $fillable = [
        'professional_id',
        'year_from',
        'year_to',
        'is_current',
        'title',
        'organization',
        'description',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'year_from' => 'integer',
            'year_to' => 'integer',
            'is_current' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }
}
