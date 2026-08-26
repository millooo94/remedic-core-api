<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Services\PublicLocaleResolver;
use App\Services\SitePopupInitializer;
use App\Services\SitePopupProjectionService;
use Illuminate\Http\Request;

class SitePopupController extends Controller
{
    public function __construct(private readonly SitePopupInitializer $initializer, private readonly SitePopupProjectionService $projection, private readonly PublicLocaleResolver $locales) {}

    public function show(Request $request)
    {
        $locale = $this->locales->resolve($request);
        $popup = $this->initializer->initialize()->load('translations');
        $translation = $popup->translations->firstWhere('locale', $locale);
        abort_if($locale->value !== 'it' && ! $translation?->isPubliclyAvailable(), 404);
        if ($translation !== null) {
            $popup->forceFill($translation->only(['eyebrow', 'title', 'body', 'primary_cta_label', 'secondary_cta_label']));
        }

        $projection = $this->projection->public($popup, $request);

        return response()->json(['data' => $projection === null ? null : [...$projection, 'locale' => $locale->value]]);
    }
}
