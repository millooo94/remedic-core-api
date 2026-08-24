<?php

namespace App\Models\Concerns;

use App\Models\FaqItem;
use App\Models\Section;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasSectionsAndFaqs
{
    public static function bootHasSectionsAndFaqs(): void
    {
        static::deleting(function (self $owner): void {
            $owner->sections()->delete();
            $owner->faqs()->delete();
        });
    }

    public function sections(): MorphMany
    {
        return $this->morphMany(Section::class, 'sectionable')->ordered();
    }

    public function faqs(): MorphMany
    {
        return $this->morphMany(FaqItem::class, 'faqable')->ordered();
    }
}
