<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfessionalAcademicSpecialization extends Model
{
    use HasFactory;

    protected $fillable = [
        'legacy_backend_id',
        'professional_id',
        'title',
        'awarded_on',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'legacy_backend_id' => 'integer',
            'awarded_on' => 'date',
            'sort_order' => 'integer',
        ];
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }
}
