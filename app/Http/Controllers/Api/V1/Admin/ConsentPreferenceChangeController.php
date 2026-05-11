<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\ConsentPreferenceChanges\ConsentPreferenceChangeIndexRequest;
use App\Http\Resources\Api\V1\Admin\ConsentPreferenceChangeResource;
use App\Models\ConsentPreferenceChange;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ConsentPreferenceChangeController extends Controller
{
    public function index(ConsentPreferenceChangeIndexRequest $request): AnonymousResourceCollection
    {
        $query = ConsentPreferenceChange::query()->with(['consentRecord.policyVersion']);

        if ($search = $request->search()) {
            $query->whereHas('consentRecord', function ($builder) use ($search): void {
                $builder->where('consent_uuid', 'like', "%{$search}%");
            });
        }

        if ($request->validated('consent_record_id')) {
            $query->where('consent_record_id', $request->integer('consent_record_id'));
        }

        if ($request->validated('event_type')) {
            $query->where('event_type', $request->validated('event_type'));
        }

        $query->orderBy('created_at', $request->direction());

        return ConsentPreferenceChangeResource::collection($query->paginate($request->perPage()));
    }

    public function show(ConsentPreferenceChange $consentPreferenceChange): ConsentPreferenceChangeResource
    {
        return new ConsentPreferenceChangeResource($consentPreferenceChange->load(['consentRecord.policyVersion']));
    }
}
