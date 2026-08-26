<?php

namespace App\Models;

use App\Enums\SupportedLocale;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FaqItemTranslation extends Model
{
    protected $fillable = ['faq_item_id', 'locale', 'question', 'answer'];

    protected function casts(): array
    {
        return ['locale' => SupportedLocale::class];
    }

    public function faqItem(): BelongsTo
    {
        return $this->belongsTo(FaqItem::class);
    }
}
