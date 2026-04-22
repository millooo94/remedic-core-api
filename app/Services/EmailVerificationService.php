<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

class EmailVerificationService
{
    public function send(User $user, string $context): bool
    {
        try {
            $user->sendEmailVerificationNotification();

            return true;
        } catch (Throwable $exception) {
            Log::warning('Unable to send verification email.', [
                'context' => $context,
                'user_id' => $user->id,
                'email' => $user->email,
                'exception' => $exception,
            ]);

            return false;
        }
    }
}
