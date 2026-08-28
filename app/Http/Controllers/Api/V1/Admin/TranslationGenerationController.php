<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\SupportedLocale;
use App\Exceptions\TranslationProviderException;
use App\Exceptions\TranslationProviderUnavailableException;
use App\Http\Controllers\Controller;
use App\Services\Translation\TranslationOrchestrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TranslationGenerationController extends Controller
{
    public function __construct(private readonly TranslationOrchestrator $translations) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string', Rule::in($this->translations->types())],
            'id' => ['required', 'integer', 'min:1'],
            'locales' => ['required', 'array', 'min:1', 'max:3'],
            'locales.*' => ['required', Rule::in(['en', 'es', 'fr']), 'distinct'],
            'regenerate' => ['sometimes', 'boolean'],
        ]);
        try {
            $results = array_map(fn (string $locale): array => $this->translations->generate($data['type'], $data['id'], SupportedLocale::from($locale), (bool) ($data['regenerate'] ?? false)), $data['locales']);
        } catch (TranslationProviderUnavailableException) {
            return response()->json(['code' => 'translation_provider_unavailable', 'message' => 'Servizio di traduzione automatica non configurato.'], 503);
        } catch (TranslationProviderException) {
            return response()->json(['code' => 'translation_provider_error', 'message' => 'Il servizio di traduzione non è disponibile. Riprova più tardi.'], 502);
        }

        return response()->json(['data' => ['results' => $results]]);
    }
}
