<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\ConsentRecords\ConsentRecordIndexRequest;
use App\Http\Resources\Api\V1\Admin\ConsentRecordResource;
use App\Models\ConsentRecord;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ConsentRecordController extends Controller
{
    public function index(ConsentRecordIndexRequest $request): AnonymousResourceCollection
    {
        $query = ConsentRecord::query()->with(['policyVersion', 'changes']);

        if ($search = $request->search()) {
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('consent_uuid', 'like', "%{$search}%")
                    ->orWhere('locale', 'like', "%{$search}%")
                    ->orWhere('source', 'like', "%{$search}%");
            });
        }

        if ($request->validated('consent_policy_version_id')) {
            $query->where('consent_policy_version_id', $request->integer('consent_policy_version_id'));
        }

        if ($status = $request->validated('status')) {
            match ($status) {
                'accepted_all' => $query->whereNull('withdrawn_at')->whereNull('rejected_at')->where('preferences', true)->where('analytics', true)->where('marketing', true),
                'rejected_all' => $query->whereNotNull('rejected_at')->where('preferences', false)->where('analytics', false)->where('marketing', false),
                'withdrawn' => $query->whereNotNull('withdrawn_at'),
                default => $query->whereNull('withdrawn_at')->where(function ($builder): void {
                    $builder
                        ->where('preferences', false)
                        ->orWhere('analytics', false)
                        ->orWhere('marketing', false);
                })->where(function ($builder): void {
                    $builder
                        ->whereNull('rejected_at')
                        ->orWhere('preferences', true)
                        ->orWhere('analytics', true)
                        ->orWhere('marketing', true);
                }),
            };
        }

        match ($request->sort()) {
            'consented_at' => $query->orderBy('consented_at', $request->direction()),
            default => $query->orderBy('created_at', $request->direction()),
        };

        return ConsentRecordResource::collection($query->paginate($request->perPage()));
    }

    public function show(ConsentRecord $consentRecord): ConsentRecordResource
    {
        return new ConsentRecordResource($consentRecord->load(['policyVersion', 'changes']));
    }
}
