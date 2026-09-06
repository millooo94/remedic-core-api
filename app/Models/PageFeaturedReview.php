<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PageFeaturedReview extends Model
{
    protected $fillable = ['page_id', 'provider', 'page_review_id'];

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(PageReview::class, 'page_review_id');
    }
}
