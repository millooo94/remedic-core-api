<?php

namespace App\Models;

use App\Enums\SupportedLocale;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SearchSynonymGroup extends Model
{
    protected $fillable = ['locale', 'canonical_term', 'is_active'];

    protected function casts(): array
    {
        return ['locale' => SupportedLocale::class, 'is_active' => 'boolean'];
    }

    public function synonyms(): HasMany
    {
        return $this->hasMany(SearchSynonym::class);
    }
}
