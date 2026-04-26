<?php

namespace App\Services;

use App\Mail\AdminAccessRequestMail;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Throwable;

class AdminApprovalService
{
    public function primaryAdminEmail(): string
    {
        return (string) config('auth.primary_admin.email', 'humancaretelemedicine@gmail.com');
    }

    public function isPrimaryAdminEmail(string $email): bool
    {
        return mb_strtolower(trim($email)) === $this->primaryAdminEmail();
    }

    public function primaryAdmin(): ?User
    {
        return User::query()
            ->where('email', $this->primaryAdminEmail())
            ->first();
    }

    public function shouldNotifyAdmin(User $user): bool
    {
        return ! $this->isPrimaryAdminEmail($user->email);
    }

    public function approvalUrl(User $user): string
    {
        return URL::temporarySignedRoute(
            'access-requests.approve',
            now()->addMinutes((int) config('auth.access_approval.link_expire_minutes', 60 * 24 * 7)),
            [
                'id' => $user->getKey(),
                'hash' => sha1($user->email),
            ],
        );
    }

    public function rejectionUrl(User $user): string
    {
        return URL::temporarySignedRoute(
            'access-requests.reject',
            now()->addMinutes((int) config('auth.access_approval.link_expire_minutes', 60 * 24 * 7)),
            [
                'id' => $user->getKey(),
                'hash' => sha1($user->email),
            ],
        );
    }

    public function sendAccessRequestNotification(User $user, string $context): bool
    {
        if (! $this->shouldNotifyAdmin($user)) {
            return true;
        }

        try {
            Mail::to($this->primaryAdminEmail())->send(new AdminAccessRequestMail(
                user: $user,
                approvalUrl: $this->approvalUrl($user),
                rejectUrl: $this->rejectionUrl($user),
            ));

            return true;
        } catch (Throwable $exception) {
            Log::warning('Unable to send access approval request email.', [
                'context' => $context,
                'user_id' => $user->id,
                'email' => $user->email,
                'admin_email' => $this->primaryAdminEmail(),
                'exception' => $exception,
            ]);

            return false;
        }
    }

    public function approve(User $user): User
    {
        $approverId = $this->primaryAdmin()?->id;

        $user->forceFill([
            'is_active' => true,
            'admin_approved_at' => $user->admin_approved_at ?? now(),
            'approved_by_user_id' => $approverId,
            'rejected_at' => null,
            'rejected_by_user_id' => null,
        ])->save();

        return $user->refresh();
    }

    public function reject(User $user): User
    {
        $approverId = $this->primaryAdmin()?->id;

        $user->forceFill([
            'is_active' => false,
            'admin_approved_at' => null,
            'approved_by_user_id' => null,
            'rejected_at' => now(),
            'rejected_by_user_id' => $approverId,
        ])->save();

        $user->tokens()->delete();

        return $user->refresh();
    }
}
