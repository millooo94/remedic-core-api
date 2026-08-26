<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\SupportedLocale;
use App\Http\Controllers\Controller;
use App\Models\BlogPost;
use App\Models\CheckupWebProfile;
use App\Models\ContentTranslation;
use App\Models\Page;
use App\Models\ProfessionalPublicProfile;
use App\Models\ServiceWebProfile;
use App\Models\SpecializationWebProfile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LocalizedContentController extends Controller
{
    private const TYPES = ['pages' => Page::class, 'medical-areas' => SpecializationWebProfile::class, 'services' => ServiceWebProfile::class, 'professionals' => ProfessionalPublicProfile::class, 'checkups' => CheckupWebProfile::class, 'blog-posts' => BlogPost::class];

    public function show(string $type, int $id, string $locale): JsonResponse
    {
        $owner = $this->owner($type, $id);
        $translation = $owner->translations()->where('locale', $this->locale($locale)->value)->first();

        return response()->json(['data' => $this->payload($owner, $translation)]);
    }

    public function store(string $type, int $id, string $locale): JsonResponse
    {
        $owner = $this->owner($type, $id);
        $locale = $this->locale($locale);
        if ($locale === SupportedLocale::IT) {
            throw ValidationException::withMessages(['locale' => 'La traduzione italiana viene creata dal backfill.']);
        }
        $italian = $owner->translations()->where('locale', 'it')->firstOrFail();
        $translation = $owner->translations()->firstOrCreate(['locale' => $locale->value], ['publication_state' => 'draft', 'source_revision' => $italian->source_revision]);

        return response()->json(['data' => $this->payload($owner, $translation)], 201);
    }

    public function update(Request $request, string $type, int $id, string $locale): JsonResponse
    {
        $owner = $this->owner($type, $id);
        $locale = $this->locale($locale);
        $translation = $owner->translations()->where('locale', $locale->value)->firstOrFail();
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:255'], 'slug' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'], 'excerpt' => ['nullable', 'string'], 'intro_text' => ['nullable', 'string'], 'short_description' => ['nullable', 'string'], 'subtitle' => ['nullable', 'string', 'max:255'], 'category_label' => ['nullable', 'string', 'max:255'], 'body' => ['nullable', 'string'], 'seo_title' => ['nullable', 'string', 'max:255'], 'seo_description' => ['nullable', 'string'], 'seo_h1' => ['nullable', 'string', 'max:255'], 'og_title' => ['nullable', 'string', 'max:255'], 'og_description' => ['nullable', 'string'], 'publication_state' => ['nullable', Rule::in(['draft', 'published'])],
        ]);
        DB::transaction(function () use ($owner, $translation, $locale, $data): void {
            $translation->fill($data);
            $publishing = $translation->publication_state === 'published';
            if ($publishing && (! filled($translation->title) || ! filled($translation->slug))) {
                throw ValidationException::withMessages(['publication_state' => 'Titolo e slug sono obbligatori per pubblicare.']);
            }
            if ($locale === SupportedLocale::IT) {
                $revision = hash('sha256', json_encode($translation->only(['title', 'slug', 'excerpt', 'intro_text', 'short_description', 'subtitle', 'category_label', 'body', 'seo_title', 'seo_description', 'seo_h1', 'og_title', 'og_description'])));
                $translation->forceFill(['source_revision' => $revision, 'reviewed_source_revision' => $revision])->save();
                $owner->translations()->where('locale', '!=', 'it')->update(['source_revision' => $revision]);
            } else {
                $source = $owner->translations()->where('locale', 'it')->firstOrFail()->source_revision;
                $translation->forceFill(['source_revision' => $source, 'reviewed_source_revision' => $publishing ? $source : $translation->reviewed_source_revision])->save();
            }
        });

        return response()->json(['data' => $this->payload($owner, $translation->refresh())]);
    }

    private function owner(string $type, int $id): Model
    {
        $class = self::TYPES[$type] ?? abort(404);

        return $class::query()->findOrFail($id);
    }

    private function locale(string $locale): SupportedLocale
    {
        return SupportedLocale::tryFrom($locale) ?? throw ValidationException::withMessages(['locale' => 'Locale non supportato.']);
    }

    private function payload(Model $owner, ?ContentTranslation $translation): array
    {
        return ['owner_id' => $owner->getKey(), 'locale' => $translation?->locale?->value, 'status' => $translation === null ? 'missing' : ($translation->needsReview() ? 'needs_review' : $translation->publication_state), 'translation' => $translation];
    }
}
