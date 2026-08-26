<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SearchSynonym extends Model
{
    protected $fillable = ['search_synonym_group_id', 'term'];

    public function group(): BelongsTo
    {
        return $this->belongsTo(SearchSynonymGroup::class, 'search_synonym_group_id');
    }
}
