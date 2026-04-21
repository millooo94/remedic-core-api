<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Profile\UpdatePasswordRequest;
use App\Http\Requests\Api\V1\Profile\UpdateProfileRequest;
use App\Http\Requests\Api\V1\Profile\UploadAvatarRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class ProfileController extends Controller
{
    public function show(Request $request): UserResource
    {
        return UserResource::make($request->user());
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $payload = $request->validated();

        $emailChanged = mb_strtolower(trim((string) $user->email)) !== mb_strtolower(trim((string) $payload['email']));

        $user->fill([
            'name' => trim((string) $payload['name']),
            'last_name' => trim((string) $payload['last_name']),
            'email' => mb_strtolower(trim((string) $payload['email'])),
        ]);

        if ($emailChanged) {
            $user->email_verified_at = null;
        }

        $user->save();

        if ($emailChanged) {
            $user->tokens()->delete();
            $user->sendEmailVerificationNotification();
        }

        return response()->json([
            'message' => $emailChanged
                ? 'Profilo aggiornato. Verifica la nuova email per continuare ad accedere.'
                : 'Profilo aggiornato con successo.',
            'user' => UserResource::make($user),
            'email_reverification_required' => $emailChanged,
        ]);
    }

    public function updateAvatar(UploadAvatarRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $file = $request->file('avatar');

        if ($user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
        }

        $path = $file->store("avatars/{$user->id}", 'public');
        $user->forceFill(['avatar_path' => $path])->save();

        return response()->json([
            'message' => 'Foto profilo aggiornata.',
            'user' => UserResource::make($user),
        ]);
    }

    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $payload = $request->validated();

        $user->forceFill([
            'password' => Hash::make($payload['password']),
        ])->save();

        return response()->json([
            'message' => 'Password aggiornata con successo.',
        ]);
    }
}
