<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Conventions\StoreConventionPartnerRequest;
use App\Http\Requests\Api\V1\Conventions\UpdateConventionPartnerRequest;
use App\Http\Requests\Api\V1\Media\UploadMasterImageRequest;
use App\Http\Resources\Api\V1\ConventionPartnerResource;
use App\Models\ConventionPartner;
use App\Services\ManagedMediaService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ConventionPartnerController extends Controller
{
    public function __construct(private readonly ManagedMediaService $media) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = ConventionPartner::query();
        if ($search = trim((string) $request->query('q', ''))) {
            $query->where('name', 'like', "%{$search}%");
        }
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $perPage = max(1, min(50, (int) $request->query('per_page', 15)));

        return ConventionPartnerResource::collection($query->publicOrder()->paginate($perPage));
    }

    public function store(StoreConventionPartnerRequest $request): ConventionPartnerResource
    {
        $partner = ConventionPartner::query()->create($request->validated());

        return new ConventionPartnerResource($partner);
    }

    public function show(ConventionPartner $convention): ConventionPartnerResource
    {
        return new ConventionPartnerResource($convention);
    }

    public function update(UpdateConventionPartnerRequest $request, ConventionPartner $convention): ConventionPartnerResource
    {
        $convention->update($request->validated());

        return new ConventionPartnerResource($convention->refresh());
    }

    public function destroy(ConventionPartner $convention): Response
    {
        $path = $convention->logo_path;
        $convention->delete();
        $this->media->deleteManagedFile($path, ["conventions/{$convention->id}/logos"]);

        return response()->noContent();
    }

    public function uploadLogo(UploadMasterImageRequest $request, ConventionPartner $convention): ConventionPartnerResource
    {
        $this->media->replace($convention, 'logo_path', $request->file('image'), "conventions/{$convention->id}/logos");

        return new ConventionPartnerResource($convention->refresh());
    }

    public function deleteLogo(ConventionPartner $convention): ConventionPartnerResource
    {
        $this->media->delete($convention, 'logo_path', ["conventions/{$convention->id}/logos"]);

        return new ConventionPartnerResource($convention->refresh());
    }
}
