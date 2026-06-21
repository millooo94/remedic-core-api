<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Specializations\StoreSpecializationRequest;
use App\Http\Requests\Api\V1\Specializations\UpdateSpecializationRequest;
use App\Http\Resources\Api\V1\SpecializationResource;
use App\Models\Specialization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SpecializationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Specialization::query()->withCount(['professionals', 'services']);

        if ($search = trim((string) $request->query('q', ''))) {
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $sort = (string) $request->query('sort', 'sort_order');
        $direction = strtolower((string) $request->query('direction', 'asc')) === 'desc' ? 'desc' : 'asc';

        match ($sort) {
            'name' => $query->orderBy('name', $direction),
            'updated_at' => $query->orderBy('updated_at', $direction),
            default => $query->orderBy('sort_order', $direction)->orderBy('name'),
        };

        $perPage = max(1, min(50, (int) $request->query('per_page', 15)));

        return SpecializationResource::collection($query->paginate($perPage));
    }

    public function options(): AnonymousResourceCollection
    {
        $specializations = Specialization::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return SpecializationResource::collection($specializations);
    }

    public function store(StoreSpecializationRequest $request): SpecializationResource
    {
        $specialization = DB::transaction(function () use ($request): Specialization {
            $specialization = Specialization::query()->create($this->validatedAttributes($request));
            $this->syncIcon($request, $specialization);

            return $specialization;
        });

        return new SpecializationResource($specialization->loadCount(['professionals', 'services']));
    }

    public function show(Specialization $specialization): SpecializationResource
    {
        return new SpecializationResource($specialization->loadCount(['professionals', 'services']));
    }

    public function update(UpdateSpecializationRequest $request, Specialization $specialization): SpecializationResource
    {
        $specialization = DB::transaction(function () use ($request, $specialization): Specialization {
            $specialization->fill($this->validatedAttributes($request));
            $specialization->save();
            $this->syncIcon($request, $specialization);

            return $specialization;
        });

        return new SpecializationResource($specialization->loadCount(['professionals', 'services']));
    }

    public function destroy(Specialization $specialization): Response
    {
        $this->deleteIcon($specialization->icon_path);
        $specialization->delete();

        return response()->noContent();
    }

    private function validatedAttributes(StoreSpecializationRequest $request): array
    {
        $payload = $request->validated();
        unset($payload['icon_svg'], $payload['remove_icon']);

        return $payload;
    }

    private function syncIcon(StoreSpecializationRequest $request, Specialization $specialization): void
    {
        $iconPath = $specialization->icon_path;

        if ($request->boolean('remove_icon')) {
            $this->deleteIcon($iconPath);
            $iconPath = null;
        }

        if ($request->hasFile('icon_svg')) {
            $this->deleteIcon($iconPath);

            $filename = Str::slug((string) $specialization->slug ?: (string) $specialization->name ?: 'specializzazione')
                .'-'.Str::lower(Str::random(8)).'.svg';

            $iconPath = $request->file('icon_svg')->storeAs(
                'specializations/icons',
                $filename,
                'public',
            );
        }

        if ($iconPath !== $specialization->icon_path) {
            $specialization->icon_path = $iconPath;
            $specialization->save();
        }
    }

    private function deleteIcon(?string $path): void
    {
        if (! is_string($path) || trim($path) === '') {
            return;
        }

        Storage::disk('public')->delete($path);
    }
}
