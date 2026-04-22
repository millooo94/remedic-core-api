<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Requests\Api\V1\Auth\RegisterRequest;
use App\Http\Requests\Api\V1\Auth\ResendVerificationRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use App\Services\EmailVerificationService;
use Illuminate\Auth\Events\Verified;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    public function __construct(
        private readonly EmailVerificationService $emailVerificationService,
    ) {
    }

    public function register(RegisterRequest $request): JsonResponse
    {
        $payload = $request->validated();

        try {
            $user = User::query()->create([
                'name' => $payload['name'],
                'last_name' => $payload['last_name'],
                'email' => $payload['email'],
                'password' => $payload['password'],
                'role' => UserRole::Admin,
                'is_active' => true,
                'email_verified_at' => null,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'email' => 'Questa email e gia registrata.',
            ]);
        }

        $verificationEmailSent = $this->emailVerificationService->send($user, 'register');

        return response()->json([
            'message' => $verificationEmailSent
                ? 'Registrazione completata. Controlla la tua email per confermare l\'account.'
                : 'Account creato correttamente, ma non siamo riusciti a inviare l\'email di verifica. Puoi richiederne una nuova dalla pagina di accesso.',
            'email' => $user->email,
            'verification_email_sent' => $verificationEmailSent,
        ], Response::HTTP_CREATED);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::query()
            ->where('email', $request->validated('email'))
            ->first();

        if (! $user || ! Hash::check($request->validated('password'), $user->password)) {
            return response()->json([
                'message' => 'Credenziali non valide.',
                'reason' => 'invalid_credentials',
            ], Response::HTTP_UNAUTHORIZED);
        }

        if (! $user->is_active) {
            return response()->json([
                'message' => 'Il tuo account non e attivo. Contatta l\'amministrazione.',
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
        $user = User::query()->where('email', $request->validated('email'))->first();

        if ($user && $user->is_active && ! $user->hasVerifiedEmail()) {
            $this->emailVerificationService->send($user, 'resend_verification_guest');
        }

        return response()->json([
            'message' => 'Se l\'indirizzo esiste ed e verificabile, abbiamo inviato una nuova email di conferma.',
        ]);
    }

    public function resendVerificationForAuthenticated(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->hasVerifiedEmail()) {
            $sent = $this->emailVerificationService->send($user, 'resend_verification_authenticated');

            return response()->json([
                'message' => $sent
                    ? 'Email di verifica inviata.'
                    : 'Non siamo riusciti a inviare l\'email di verifica. Riprova tra poco.',
                'verification_email_sent' => $sent,
            ], $sent ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return response()->json([
            'message' => 'Email gia verificata.',
            'verification_email_sent' => true,
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
        $frontendUrl = rtrim((string) (config('app.frontend_url') ?: (config('app.cors_allowed_origins')[0] ?? 'http://localhost:4200')), '/');

        return redirect()->away($frontendUrl.'/login?verification='.$status);
    }
}
