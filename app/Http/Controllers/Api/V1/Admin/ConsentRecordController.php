<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Admin\ConsentRecordResource;
use App\Models\ConsentRecord;
use App\Services\ConsentConfigurationInitializer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ConsentRecordController extends Controller
{
    public function __construct(private readonly ConsentConfigurationInitializer $configuration) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate(['q' => ['nullable', 'string', 'max:255'], 'configuration_version' => ['nullable', 'integer', 'min:1'], 'preferences' => ['nullable', 'boolean'], 'statistics' => ['nullable', 'boolean'], 'marketing' => ['nullable', 'boolean'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $currentVersion = $this->configuration->initialize()->configuration_version;
        $query = ConsentRecord::query()->with('events')->latest('last_updated_at');

        if ($search = $validated['q'] ?? null) {
            $query->where('public_id', 'like', '%'.trim($search).'%');
        }
        foreach (['configuration_version', 'preferences', 'statistics', 'marketing'] as $filter) {
            if (array_key_exists($filter, $validated) && $validated[$filter] !== null) {
                $query->where($filter, $validated[$filter]);
            }
        }
        $records = $query->paginate($validated['per_page'] ?? 20);
        $records->getCollection()->each(fn (ConsentRecord $record) => $record->setAttribute('current_configuration_version', $currentVersion));

        return ConsentRecordResource::collection($records);
    }

    public function show(ConsentRecord $consentRecord): ConsentRecordResource
    {
        $consentRecord->setAttribute('current_configuration_version', $this->configuration->initialize()->configuration_version);

        return new ConsentRecordResource($consentRecord->load('events'));
    }
}
