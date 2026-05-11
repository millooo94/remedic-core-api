<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\BackofficeIndexRequest;
use App\Http\Requests\Api\V1\Admin\Users\StoreBackofficeUserRequest;
use App\Http\Requests\Api\V1\Admin\Users\UpdateBackofficeUserRequest;
use App\Http\Resources\Api\V1\Admin\BackofficeUserResource;
use App\Models\User;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
    public function index(BackofficeIndexRequest $request): AnonymousResourceCollection
    {
        $query = User::query()->with(['roles.permissions']);

        if ($search = $request->search()) {
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->has('is_active')) {
            $query->where('is_active', (bool) $request->boolean('is_active'));
        }

        $sort = $request->sort();
        $direction = $request->direction();

        match ($sort) {
            'email' => $query->orderBy('email', $direction),
            'updated_at' => $query->orderBy('updated_at', $direction),
            'last_login_at' => $query->orderBy('last_login_at', $direction),
            default => $query->orderBy('name')->orderBy('last_name'),
        };

        return BackofficeUserResource::collection($query->paginate($request->perPage()));
    }

    public function store(StoreBackofficeUserRequest $request): BackofficeUserResource
    {
        $user = DB::transaction(function () use ($request): User {
            $payload = $request->validated();
            $roles = $payload['roles'];
            unset($payload['roles'], $payload['password_confirmation']);

            $payload['role'] = UserRole::Admin;
            $payload['is_active'] ??= true;
            $payload['approval_requested_at'] ??= now();
            $payload['admin_approved_at'] ??= now();
            $payload['email_verified_at'] ??= now();

            $user = User::query()->create($payload);
            $user->syncRoles($roles);

            return $user->load(['roles.permissions']);
        });

        return new BackofficeUserResource($user);
    }

    public function show(User $user): BackofficeUserResource
    {
        return new BackofficeUserResource($user->load(['roles.permissions']));
    }

    public function update(UpdateBackofficeUserRequest $request, User $user): BackofficeUserResource
    {
        $user = DB::transaction(function () use ($request, $user): User {
            $payload = $request->validated();
            $roles = $payload['roles'];
            unset($payload['roles'], $payload['password_confirmation']);

            $payload['role'] = UserRole::Admin;

            if (empty($payload['password'])) {
                unset($payload['password']);
            }

            $user->fill($payload);
            $user->save();
            $user->syncRoles($roles);

            return $user->load(['roles.permissions']);
        });

        return new BackofficeUserResource($user);
    }

    public function destroy(User $user): Response
    {
        if ($user->isPrimaryAdmin()) {
            abort(Response::HTTP_FORBIDDEN, 'Non puoi eliminare l’admin principale.');
        }

        $user->delete();

        return response()->noContent();
    }
}
