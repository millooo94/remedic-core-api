<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Services\PublicLocaleResolver;
use App\Services\SiteNavigationInitializer;
use App\Services\SiteNavigationProjectionService;
use Illuminate\Http\Request;

class SiteNavigationController extends Controller
{
    public function __construct(private readonly SiteNavigationInitializer $initializer, private readonly SiteNavigationProjectionService $projection, private readonly PublicLocaleResolver $locales) {}

    public function show(Request $request)
    {
        $locale = $this->locales->resolve($request);
        $navigation = $this->initializer->initialize()->load('translations');
        $translation = $navigation->translations->firstWhere('locale', $locale);
        abort_if($locale->value !== 'it' && ! $translation?->isPubliclyAvailable(), 404);
        if ($translation?->configuration !== null) {
            $navigation->configuration = $translation->configuration;
        }

        return response()->json(['data' => [...$this->projection->public($navigation, $request), 'locale' => $locale->value]]);
    }
}
