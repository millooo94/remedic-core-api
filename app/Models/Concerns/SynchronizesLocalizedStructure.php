<?php

namespace App\Models\Concerns;

use App\Models\FaqItem;
use App\Models\Section;
use Illuminate\Database\Eloquent\Model;

/** Structural records are shared, but their Italian source also drives review state. */
trait SynchronizesLocalizedStructure
{
    public static function bootSynchronizesLocalizedStructure(): void
    {
        static::saved(function (Model $structure): void {
            if ($structure instanceof Section) {
                $structure->translations()->updateOrCreate(['locale' => 'it'], [
                    'title' => $structure->title,
                    'subtitle' => $structure->subtitle,
                    'content' => $structure->content,
                ]);
                $owner = $structure->sectionable;
            } elseif ($structure instanceof FaqItem) {
                $structure->translations()->updateOrCreate(['locale' => 'it'], [
                    'question' => $structure->question,
                    'answer' => $structure->answer,
                ]);
                $owner = $structure->faqable;
            } else {
                return;
            }

            if ($owner !== null && method_exists($owner, 'translations')) {
                $italian = $owner->translations()->where('locale', 'it')->first();
                if ($italian !== null) {
                    $revision = hash('sha256', json_encode([
                        'content' => $italian->only(['title', 'slug', 'excerpt', 'intro_text', 'short_description', 'subtitle', 'category_label', 'body', 'seo_title', 'seo_description', 'seo_h1', 'og_title', 'og_description']),
                        'sections' => $owner->sections()->active()->orderBy('id')->get(['id', 'title', 'subtitle', 'content'])->toArray(),
                        'faqs' => $owner->faqs()->active()->orderBy('id')->get(['id', 'question', 'answer'])->toArray(),
                    ], JSON_THROW_ON_ERROR));
                    $italian->forceFill(['source_revision' => $revision, 'reviewed_source_revision' => $revision])->save();
                    $owner->translations()->where('locale', '!=', 'it')->update(['source_revision' => $revision]);
                }
            }
        });
    }
}
