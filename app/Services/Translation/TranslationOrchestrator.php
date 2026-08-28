<?php

namespace App\Services\Translation;

use App\Contracts\TranslationProvider;
use App\Enums\SupportedLocale;
use App\Models\BlogPost;
use App\Models\CheckupWebProfile;
use App\Models\ConsentCategory;
use App\Models\ContentTranslation;
use App\Models\Page;
use App\Models\ProfessionalPublicProfile;
use App\Models\ServiceWebProfile;
use App\Models\SpecializationWebProfile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Explicit allowlist for public editorial records; no private or operational model is addressable. */
class TranslationOrchestrator
{
    private const TYPES = [
        'pages' => Page::class,
        'medical-areas' => SpecializationWebProfile::class,
        'services' => ServiceWebProfile::class,
        'professionals' => ProfessionalPublicProfile::class,
        'checkups' => CheckupWebProfile::class,
        'blog-posts' => BlogPost::class,
        'consent-categories' => ConsentCategory::class,
    ];

    /** Slugs, URLs, relations, prices and structure deliberately never enter this list. */
    private const FIELDS = ['title', 'excerpt', 'intro_text', 'short_description', 'subtitle', 'category_label', 'body', 'seo_title', 'seo_description', 'seo_h1', 'og_title', 'og_description', 'label', 'description'];

    public function __construct(private readonly TranslationProvider $provider) {}

    /** @return array{locale: string, status: string, mode: string, translated_fields: list<string>} */
    public function generate(string $type, int $id, SupportedLocale $target, bool $regenerate = false): array
    {
        if ($target === SupportedLocale::IT) {
            throw ValidationException::withMessages(['locales' => 'Italiano è la lingua sorgente e non può essere generato.']);
        }
        $owner = $this->owner($type, $id);
        $source = $owner->translations()->where('locale', SupportedLocale::IT->value)->first();
        if (! $source instanceof ContentTranslation) {
            throw ValidationException::withMessages(['source' => 'Il contenuto italiano richiesto non è disponibile.']);
        }
        $segments = $this->segments($source);
        if ($segments === []) {
            throw ValidationException::withMessages(['source' => 'Il contenuto italiano non contiene campi editoriali traducibili.']);
        }
        if (count($segments) > 20 || array_sum(array_map('mb_strlen', $segments)) > 30000) {
            throw ValidationException::withMessages(['source' => 'Il contenuto supera i limiti di traduzione consentiti.']);
        }

        $existing = $owner->translations()->where('locale', $target->value)->first();
        if ($existing instanceof ContentTranslation && ! $existing->needsReview() && ! $regenerate) {
            abort(409, 'La traduzione esistente è protetta: conferma la rigenerazione per sostituirla.');
        }
        $translated = $this->provider->translate($segments, $target);
        if (array_keys($translated) !== array_keys($segments) || count($translated) !== count($segments)) {
            throw ValidationException::withMessages(['provider' => 'Il provider ha restituito una risposta incompleta.']);
        }

        return DB::transaction(function () use ($owner, $source, $target, $existing, $translated, $regenerate): array {
            $translation = $existing ?? new ContentTranslation(['locale' => $target]);
            $translation->fill($translated);
            $translation->forceFill([
                'locale' => $target,
                'publication_state' => 'draft',
                'source_revision' => $source->source_revision,
                'reviewed_source_revision' => null,
            ]);
            $owner->translations()->save($translation);

            return ['locale' => $target->value, 'status' => 'needs_review', 'mode' => $regenerate ? 'regenerated' : 'generated', 'translated_fields' => array_keys($translated)];
        });
    }

    /** @return list<string> */
    public function types(): array
    {
        return array_keys(self::TYPES);
    }

    private function owner(string $type, int $id): Model
    {
        $class = self::TYPES[$type] ?? abort(404);

        return $class::query()->findOrFail($id);
    }

    /** @return array<string, string> */
    private function segments(ContentTranslation $source): array
    {
        $segments = [];
        foreach (self::FIELDS as $field) {
            $value = $source->getAttribute($field);
            if (is_string($value) && filled($value)) {
                $segments[$field] = $value;
            }
        }

        return $segments;
    }
}
