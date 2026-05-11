<?php

namespace App\Models\Concerns;

use App\Models\FaqItem;
use App\Models\Section;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasSectionsAndFaqs
{
    public function sections(): MorphMany
    {
        return $this->morphMany(Section::class, 'sectionable');
    }

    public function faqs(): MorphMany
    {
        return $this->morphMany(FaqItem::class, 'faqable');
    }
}
