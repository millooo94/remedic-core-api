<?php

namespace App\Services\Translation;

use App\Contracts\TranslationProvider;
use App\Enums\SupportedLocale;
use App\Exceptions\TranslationProviderException;
use App\Exceptions\TranslationProviderUnavailableException;
use Google\Auth\ApplicationDefaultCredentials;
use Illuminate\Http\Client\Factory as HttpFactory;
use Throwable;

class GoogleCloudTranslationProvider implements TranslationProvider
{
    private const SCOPE = 'https://www.googleapis.com/auth/cloud-translation';

    public function __construct(private readonly HttpFactory $http) {}

    public function translate(array $segments, SupportedLocale $target): array
    {
        $project = (string) config('services.google_translation.project');
        if (! (bool) config('services.google_translation.enabled') || blank($project)) {
            throw new TranslationProviderUnavailableException('Translation provider unavailable.');
        }

        try {
            $credentials = ApplicationDefaultCredentials::getCredentials([self::SCOPE]);
            $token = $credentials->fetchAuthToken()['access_token'] ?? null;
        } catch (Throwable $exception) {
            throw new TranslationProviderUnavailableException('Translation provider unavailable.', previous: $exception);
        }
        if (! is_string($token) || $token === '') {
            throw new TranslationProviderUnavailableException('Translation provider unavailable.');
        }

        try {
            $response = $this->http->timeout((int) config('services.google_translation.timeout_seconds', 12))
                ->acceptJson()
                ->withToken($token)
                ->post("https://translation.googleapis.com/v3/projects/{$project}/locations/global:translateText", [
                    'contents' => array_values($segments),
                    'mimeType' => 'text/plain',
                    'sourceLanguageCode' => SupportedLocale::IT->value,
                    'targetLanguageCode' => $target->value,
                ]);
        } catch (Throwable $exception) {
            throw new TranslationProviderException('Translation provider request failed.', previous: $exception);
        }

        if (! $response->successful()) {
            throw new TranslationProviderException('Translation provider request failed.');
        }
        $translations = $response->json('translations');
        if (! is_array($translations) || count($translations) !== count($segments)) {
            throw new TranslationProviderException('Translation provider returned an invalid response.');
        }
        $values = array_map(fn (mixed $item): mixed => is_array($item) ? ($item['translatedText'] ?? null) : null, $translations);
        if (count(array_filter($values, 'is_string')) !== count($segments)) {
            throw new TranslationProviderException('Translation provider returned an invalid response.');
        }

        return array_combine(array_keys($segments), $values) ?: throw new TranslationProviderException('Translation provider returned an invalid response.');
    }
}
