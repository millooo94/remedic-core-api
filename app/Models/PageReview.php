<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PageReview extends Model
{
    use HasFactory;

    protected $fillable = ['page_id', 'provider', 'external_id', 'author_name', 'body', 'rating', 'reviewed_at', 'source_metadata', 'synced_at', 'is_available'];

    protected function casts(): array
    {
        return ['source_metadata' => 'array', 'reviewed_at' => 'datetime', 'synced_at' => 'datetime', 'is_available' => 'boolean'];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }
}
