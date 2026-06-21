<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\BackofficeIndexRequest;
use App\Http\Requests\Api\V1\Admin\Redirects\StoreRedirectRequest;
use App\Http\Requests\Api\V1\Admin\Redirects\UpdateRedirectRequest;
use App\Http\Resources\Api\V1\Admin\RedirectResource;
use App\Models\Redirect;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class RedirectController extends Controller
{
    public function index(BackofficeIndexRequest $request): AnonymousResourceCollection
    {
        $query = Redirect::query();

        if ($search = $request->search()) {
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('from_path', 'like', "%{$search}%")
                    ->orWhere('to_path', 'like', "%{$search}%");
            });
        }

        if ($request->has('is_active')) {
            $query->where('is_active', (bool) $request->boolean('is_active'));
        }

        if ($request->has('is_automatic')) {
            $query->where('is_automatic', (bool) $request->boolean('is_automatic'));
        }

        $sort = $request->sort();
        $direction = $request->direction();

        match ($sort) {
            'is_automatic' => $query->orderBy('is_automatic', $direction),
            'to_path' => $query->orderBy('to_path', $direction),
            'http_code' => $query->orderBy('http_code', $direction),
            'updated_at' => $query->orderBy('updated_at', $direction),
            default => $query->orderBy('from_path', $direction),
        };

        return RedirectResource::collection($query->paginate($request->perPage()));
    }

    public function store(StoreRedirectRequest $request): RedirectResource
    {
        $redirect = Redirect::create([
            ...$request->validated(),
            'is_automatic' => false,
            'source_type' => null,
            'source_id' => null,
        ]);

        return new RedirectResource($redirect);
    }

    public function show(Redirect $redirect): RedirectResource
    {
        return new RedirectResource($redirect);
    }

    public function update(UpdateRedirectRequest $request, Redirect $redirect): RedirectResource
    {
        $redirect->update($request->validated());

        return new RedirectResource($redirect->fresh());
    }

    public function destroy(Redirect $redirect): Response
    {
        $redirect->delete();

        return response()->noContent();
    }
}
