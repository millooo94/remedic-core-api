<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Requests\Api\V1\Auth\RegisterRequest;
use App\Http\Requests\Api\V1\Auth\ResendApprovalRequest;
use App\Http\Requests\Api\V1\Auth\ResendVerificationRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use App\Services\AdminApprovalService;
use App\Services\BackofficeAccess\BackofficeAccessReconciler;
use App\Services\EmailVerificationService;
use Illuminate\Auth\Events\Verified;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    public function __construct(
        private readonly AdminApprovalService $adminApprovalService,
        private readonly EmailVerificationService $emailVerificationService,
        private readonly BackofficeAccessReconciler $backofficeAccessReconciler,
    ) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $payload = $request->validated();

        try {
            $isPrimaryAdmin = $this->adminApprovalService->isPrimaryAdminEmail($payload['email']);

            $user = User::query()->create([
                'name' => $payload['name'],
                'last_name' => $payload['last_name'],
                'email' => $payload['email'],
                'password' => $payload['password'],
                'role' => UserRole::Admin,
                'is_active' => true,
                'approval_requested_at' => now(),
                'admin_approved_at' => $isPrimaryAdmin ? now() : null,
                'approved_by_user_id' => null,
                'rejected_at' => null,
                'rejected_by_user_id' => null,
                'email_verified_at' => null,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'email' => 'Questa email e gia registrata.',
            ]);
        }

        $verificationEmailSent = $this->emailVerificationService->send($user, 'register');
        $approvalRequestSent = $this->adminApprovalService->sendAccessRequestNotification($user, 'register');

        return response()->json([
            'message' => $verificationEmailSent
                ? 'Registrazione completata. Conferma la tua email e attendi l\'approvazione dell\'amministratore prima di accedere.'
                : 'Account creato correttamente, ma non siamo riusciti a inviare l\'email di verifica. Puoi richiederne una nuova dalla pagina di accesso.',
            'email' => $user->email,
            'verification_email_sent' => $verificationEmailSent,
            'approval_request_sent' => $approvalRequestSent,
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

        if (! $user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Devi prima confermare il tuo indirizzo email.',
                'reason' => 'email_not_verified',
                'email' => $user->email,
            ], Response::HTTP_FORBIDDEN);
        }

        if (! $user->is_active) {
            return response()->json([
                'message' => 'Il tuo account non e attivo.',
                'reason' => 'user_inactive',
            ], Response::HTTP_FORBIDDEN);
        }

        if (! $user->hasAdminApproval()) {
            return response()->json([
                'message' => 'La tua richiesta e in attesa di approvazione da parte dell\'amministratore.',
                'reason' => 'admin_approval_pending',
                'email' => $user->email,
            ], Response::HTTP_FORBIDDEN);
        }

        $this->backofficeAccessReconciler->reconcileIfNeeded();
        $user->unsetRelation('roles')->unsetRelation('permissions');

        $user->forceFill(['last_login_at' => now()])->save();

        return response()->json([
            'token' => $user->createToken($request->validated('device_name', 'web'))->plainTextToken,
            'user' => UserResource::make($user),
        ]);
    }

    public function me(Request $request): UserResource
    {
        $this->backofficeAccessReconciler->reconcileIfNeeded();
        $request->user()->unsetRelation('roles')->unsetRelation('permissions');

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

    public function resendApprovalRequest(ResendApprovalRequest $request): JsonResponse
    {
        $user = User::query()->where('email', $request->validated('email'))->first();

        if ($user && $user->is_active && $user->hasVerifiedEmail() && ! $user->hasAdminApproval()) {
            $this->adminApprovalService->sendAccessRequestNotification($user, 'resend_approval_request');
        }

        return response()->json([
            'message' => 'Se la richiesta e ancora in attesa, abbiamo avvisato nuovamente l\'amministratore.',
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

        return $this->verificationRedirect('verified', $user);
    }

    public function approveAccessRequest(Request $request, int $id, string $hash)
    {
        $user = $this->resolveApprovalTarget($request, $id, $hash);

        if (! $user) {
            return $this->approvalRedirect('invalid');
        }

        $this->adminApprovalService->approve($user);

        return $this->approvalRedirect('approved', $user);
    }

    public function rejectAccessRequest(Request $request, int $id, string $hash)
    {
        $user = $this->resolveApprovalTarget($request, $id, $hash);

        if (! $user) {
            return $this->approvalRedirect('invalid');
        }

        $this->adminApprovalService->reject($user);

        return $this->approvalRedirect('rejected', $user);
    }

    private function resolveApprovalTarget(Request $request, int $id, string $hash): ?User
    {
        if (! $request->hasValidSignature()) {
            return null;
        }

        $user = User::query()->find($id);

        if (! $user) {
            return null;
        }

        if (! hash_equals((string) $hash, sha1($user->email))) {
            return null;
        }

        return $user;
    }

    private function verificationRedirect(string $status, ?User $user = null)
    {
        $query = ['verification' => $status];

        if ($status === 'verified' && $user) {
            $query['approval'] = $user->hasAdminApproval()
                ? 'approved'
                : ($user->is_active ? 'pending' : 'rejected');
            $query['email'] = $user->email;
        }

        return redirect()->away($this->frontendLoginUrl($query));
    }

    private function approvalRedirect(string $status, ?User $user = null)
    {
        $query = ['approval' => $status];

        if ($user) {
            $query['email'] = $user->email;
        }

        return redirect()->away($this->frontendLoginUrl($query));
    }

    private function frontendLoginUrl(array $query = []): string
    {
        $frontendUrl = rtrim((string) (config('app.frontend_url') ?: (config('app.cors_allowed_origins')[0] ?? 'http://localhost:4200')), '/');
        $normalizedQuery = Arr::where($query, fn (mixed $value): bool => $value !== null && $value !== '');

        return $frontendUrl.'/login?'.http_build_query($normalizedQuery);
    }
}
