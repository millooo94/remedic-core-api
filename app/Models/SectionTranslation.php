<?php

namespace App\Models;

use App\Enums\SupportedLocale;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SectionTranslation extends Model
{
    protected $fillable = ['section_id', 'locale', 'title', 'subtitle', 'content'];

    protected function casts(): array
    {
        return ['locale' => SupportedLocale::class];
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }
}
