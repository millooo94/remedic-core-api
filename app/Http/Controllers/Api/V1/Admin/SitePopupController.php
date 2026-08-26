<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\SitePopupInitializer;
use App\Services\SitePopupProjectionService;
use App\Support\Navigation\SiteNavigationRegistry;
use Carbon\Carbon;
use Illuminate\Http\Request;

class SitePopupController extends Controller
{
    public function __construct(private readonly SitePopupInitializer $initializer, private readonly SitePopupProjectionService $projection) {}

    public function show(Request $request)
    {
        return response()->json(['data' => $this->projection->admin($this->initializer->initialize(), $request)]);
    }

    public function update(Request $request)
    {
        $allowed = ['is_active', 'start_at', 'end_at', 'eyebrow', 'title', 'body', 'primary_cta_label', 'primary_cta_target', 'secondary_cta_label', 'secondary_cta_target'];
        abort_unless(count(array_diff(array_keys($request->all()), $allowed)) === 0, 422, 'Il payload contiene campi non consentiti.');
        $data = $request->validate([
            'is_active' => ['required', 'boolean'], 'start_at' => ['nullable', 'date'], 'end_at' => ['nullable', 'date'],
            'eyebrow' => ['nullable', 'string', 'max:80'], 'title' => ['nullable', 'string', 'max:160'], 'body' => ['nullable', 'string', 'max:4000'],
            'primary_cta_label' => ['nullable', 'string', 'max:80'], 'primary_cta_target' => ['nullable', 'string', 'max:80'],
            'secondary_cta_label' => ['nullable', 'string', 'max:80'], 'secondary_cta_target' => ['nullable', 'string', 'max:80'],
        ]);
        foreach ([['primary_cta_label', 'primary_cta_target'], ['secondary_cta_label', 'secondary_cta_target']] as [$label, $target]) {
            abort_unless(filled($data[$label] ?? null) === filled($data[$target] ?? null), 422, 'Ogni CTA richiede etichetta e destinazione.');
            if (filled($data[$target] ?? null)) {
                abort_unless(SiteNavigationRegistry::targetExists($data[$target]), 422, 'La destinazione CTA non è valida.');
            }
        }
        if (($data['start_at'] ?? null) !== null && ($data['end_at'] ?? null) !== null) {
            abort_unless(Carbon::parse($data['end_at'])->gt(Carbon::parse($data['start_at'])), 422, 'La data di fine deve essere successiva alla data di inizio.');
        }
        $popup = $this->initializer->initialize();
        if ($data['is_active']) {
            abort_unless(filled($data['title']) || filled($data['body']) || $popup->image_path !== null || filled($data['primary_cta_label']), 422, 'Un popup attivo deve contenere almeno un contenuto.');
        }
        $popup->update($data);

        return response()->json(['data' => $this->projection->admin($popup->refresh(), $request)]);
    }

    public function republish(Request $request)
    {
        $popup = $this->initializer->initialize();
        $popup->increment('campaign_version');

        return response()->json(['data' => $this->projection->admin($popup->refresh(), $request)]);
    }
}
