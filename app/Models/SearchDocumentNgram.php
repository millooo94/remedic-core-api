<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SearchDocumentNgram extends Model
{
    protected $fillable = ['search_document_id', 'gram'];

    public function document(): BelongsTo
    {
        return $this->belongsTo(SearchDocument::class, 'search_document_id');
    }
}
