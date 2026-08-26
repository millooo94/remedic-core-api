<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Applications\ApplicationTypeRequest;
use App\Http\Resources\Api\V1\ApplicationTypeResource;
use App\Http\Resources\Api\V1\PublicApplicationTypeResource;
use App\Models\ApplicationType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class ApplicationTypeController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return ApplicationTypeResource::collection(ApplicationType::query()->publicOrder()->paginate(50));
    }

    public function publicIndex(): AnonymousResourceCollection
    {
        return PublicApplicationTypeResource::collection(ApplicationType::query()->where('is_active', true)->publicOrder()->get());
    }

    public function store(ApplicationTypeRequest $request): ApplicationTypeResource
    {
        $attributes = $request->validated();
        $attributes['sort_order'] = (int) ApplicationType::max('sort_order') + 1;

        return new ApplicationTypeResource(ApplicationType::query()->create($attributes));
    }

    public function update(ApplicationTypeRequest $request, ApplicationType $applicationType): ApplicationTypeResource
    {
        $applicationType->update($request->validated());

        return new ApplicationTypeResource($applicationType);
    }

    public function destroy(ApplicationType $applicationType): Response|JsonResponse
    {
        if ($applicationType->applications()->exists()) {
            return response()->json(['message' => 'Il tipo è già referenziato da candidature e non può essere eliminato.'], 409);
        }
        $applicationType->delete();

        return response()->noContent();
    }

    public function reorder(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate(['public_ids' => ['required', 'array'], 'public_ids.*' => ['required', 'uuid', 'distinct', 'exists:application_types,public_id']]);
        if (count($data['public_ids']) !== ApplicationType::count()) {
            throw ValidationException::withMessages(['ids' => 'L’ordinamento deve includere tutti i tipi.']);
        }
        foreach ($data['public_ids'] as $order => $publicId) {
            ApplicationType::query()->where('public_id', $publicId)->update(['sort_order' => $order]);
        }

        return ApplicationTypeResource::collection(ApplicationType::query()->publicOrder()->get());
    }
}
