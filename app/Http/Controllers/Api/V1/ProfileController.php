<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Profile\UpdatePasswordRequest;
use App\Http\Requests\Api\V1\Profile\UpdateProfileRequest;
use App\Http\Requests\Api\V1\Profile\UploadAvatarRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use App\Services\EmailVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class ProfileController extends Controller
{
    public function __construct(
        private readonly EmailVerificationService $emailVerificationService,
    ) {
    }

    public function show(Request $request): UserResource
    {
        return UserResource::make($request->user());
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $payload = $request->validated();

        $emailChanged = $user->email !== $payload['email'];

        $user->fill([
            'name' => $payload['name'],
            'last_name' => $payload['last_name'],
            'email' => $payload['email'],
        ]);

        if ($emailChanged) {
            $user->email_verified_at = null;
        }

        $user->save();

        $verificationEmailSent = null;

        if ($emailChanged) {
            $user->tokens()->delete();
            $verificationEmailSent = $this->emailVerificationService->send($user, 'profile_email_change');
        }

        return response()->json([
            'message' => match (true) {
                ! $emailChanged => 'Profilo aggiornato con successo.',
                $verificationEmailSent === true => 'Profilo aggiornato. Verifica la nuova email per continuare ad accedere.',
                default => 'Profilo aggiornato. Non siamo riusciti a inviare la verifica alla nuova email. Puoi richiederne una nuova dalla pagina di accesso.',
            },
            'user' => UserResource::make($user),
            'email_reverification_required' => $emailChanged,
            'verification_email_sent' => $verificationEmailSent,
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
