<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SearchDocument extends Model
{
    protected $fillable = ['source_type', 'source_id', 'locale', 'result_type', 'href', 'title', 'subtitle', 'excerpt', 'image_path', 'normalized_title', 'normalized_text', 'searchable_tokens'];

    public function ngrams(): HasMany
    {
        return $this->hasMany(SearchDocumentNgram::class);
    }
}
