<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Requests\Api\V1\Auth\RegisterRequest;
use App\Http\Requests\Api\V1\Auth\ResendVerificationRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $payload = $request->validated();

        $user = User::query()->create([
            'name' => trim((string) $payload['name']),
            'last_name' => trim((string) $payload['last_name']),
            'email' => mb_strtolower(trim((string) $payload['email'])),
            'password' => $payload['password'],
            'role' => 'admin',
            'is_active' => true,
            'email_verified_at' => null,
        ]);

        $user->sendEmailVerificationNotification();

        return response()->json([
            'message' => 'Registrazione completata. Controlla la tua email per confermare l\'account.',
        ], Response::HTTP_CREATED);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::query()
            ->where('email', mb_strtolower(trim((string) $request->validated('email'))))
            ->first();

        if (! $user || ! Hash::check($request->validated('password'), $user->password)) {
            return response()->json([
                'message' => 'Credenziali non valide.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        if (! $user->is_active) {
            return response()->json([
                'message' => 'Il tuo account non è attivo. Contatta l\'amministrazione.',
                'reason' => 'user_inactive',
            ], Response::HTTP_FORBIDDEN);
        }

        if (! $user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Email non verificata. Controlla la tua casella o richiedi un nuovo invio.',
                'reason' => 'email_not_verified',
                'email' => $user->email,
            ], Response::HTTP_FORBIDDEN);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        return response()->json([
            'token' => $user->createToken($request->validated('device_name', 'web'))->plainTextToken,
            'user' => UserResource::make($user),
        ]);
    }

    public function me(Request $request): UserResource
    {
        return UserResource::make($request->user());
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json([
            'message' => 'Logout eseguito.',
        ]);
    }

    public function resendVerification(ResendVerificationRequest $request): JsonResponse
    {
        $user = User::query()->where('email', mb_strtolower(trim((string) $request->validated('email'))))->first();

        if ($user && $user->is_active && ! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        return response()->json([
            'message' => 'Se l\'indirizzo esiste ed è verificabile, abbiamo inviato una nuova email di conferma.',
        ]);
    }

    public function resendVerificationForAuthenticated(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        return response()->json([
            'message' => 'Email di verifica inviata.',
        ]);
    }

    public function verifyEmail(Request $request, int $id, string $hash)
    {
        if (! $request->hasValidSignature()) {
            return $this->verificationRedirect('invalid');
        }

        $user = User::query()->find($id);
        if (! $user) {
            return $this->verificationRedirect('invalid');
        }

        if (! hash_equals((string) $hash, sha1($user->getEmailForVerification()))) {
            return $this->verificationRedirect('invalid');
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new Verified($user));
        }

        return $this->verificationRedirect('verified');
    }

    private function verificationRedirect(string $status)
    {
        $frontendUrl = rtrim((string) config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:4200')), '/');

        return redirect()->away($frontendUrl.'/login?verification='.$status);
    }
}
