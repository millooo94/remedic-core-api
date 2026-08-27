<?php

namespace App\Models;

use App\Enums\PublicationState;
use App\Enums\SupportedLocale;
use Illuminate\Database\Eloquent\Model;

class ServicePricingPresentationTranslation extends Model
{
    protected $fillable = ['service_pricing_profile_presentation_id', 'service_pricing_item_presentation_id', 'locale', 'label', 'note', 'publication_state', 'source_revision', 'reviewed_source_revision'];

    protected function casts(): array
    {
        return ['locale' => SupportedLocale::class, 'publication_state' => PublicationState::class];
    }

    public function isPubliclyAvailable(): bool
    {
        return $this->publication_state === PublicationState::Published && filled($this->label) && ($this->locale === SupportedLocale::IT || $this->source_revision === $this->reviewed_source_revision);
    }
}
