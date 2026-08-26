<?php

namespace App\Services;

use App\Enums\AdminPermission;
use App\Enums\NotificationSeverity;
use App\Mail\CareerApplicationCandidateMail;
use App\Mail\CareerApplicationInternalMail;
use App\Models\ApplicationSetting;
use App\Models\ApplicationType;
use App\Models\JobApplication;
use App\Notifications\InternalNotificationAction;
use App\Notifications\InternalNotificationPayload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class CareerApplicationService
{
    public function __construct(private readonly InternalNotificationService $notifications) {}

    /** @param array<string,mixed> $validated */
    public function submit(array $validated, ?UploadedFile $cv): JobApplication
    {
        $type = ApplicationType::query()->where('key', $validated['application_type'])->where('is_active', true)->firstOrFail();
        $path = null;
        try {
            if ($cv !== null) {
                $path = $cv->store('career-applications/cv', 'local');
            }
            $application = DB::transaction(function () use ($validated, $cv, $path, $type): JobApplication {
                return JobApplication::query()->create([
                    ...Arr::except($validated, ['cv', 'privacy_consent', 'application_type']),
                    'application_type_id' => $type->id, 'application_type_name_snapshot' => $type->name, 'application_type_key_snapshot' => $type->key,
                    'locale' => $validated['locale'] ?? 'it', 'privacy_consent_at' => now(), 'status' => 'new', 'submitted_at' => now(),
                    'cv_path' => $path, 'cv_original_name' => $cv?->getClientOriginalName(), 'cv_mime_type' => $cv?->getMimeType(), 'cv_size_bytes' => $cv?->getSize(),
                ]);
            });
        } catch (\Throwable $exception) {
            if ($path !== null) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }
        $this->afterPersist($application);

        return $application;
    }

    public function markFirstOpened(JobApplication $application, int $userId): JobApplication
    {
        JobApplication::query()->whereKey($application->id)->whereNull('first_opened_at')->update(['first_opened_at' => now(), 'first_opened_by_user_id' => $userId]);

        return $application->fresh();
    }

    private function afterPersist(JobApplication $application): void
    {
        $payload = new InternalNotificationPayload('career_application_received', 'career_applications', 'Nuova candidatura ricevuta', $application->first_name.' '.$application->last_name.' — '.$application->application_type_name_snapshot, NotificationSeverity::INFO, new InternalNotificationAction('career_applications', ['application' => $application->public_id]), 'career_application', $application->public_id, 'career_application:'.$application->public_id);
        $this->notifications->notifyUsersWithPermission(AdminPermission::VIEW_CAREER_APPLICATIONS->value, $payload);
        try {
            Mail::to($application->email)->send(new CareerApplicationCandidateMail($application));
        } catch (\Throwable $exception) {
            Log::warning('Career application candidate email failed.', ['application' => $application->public_id, 'exception' => $exception->getMessage()]);
        }
        $recipient = ApplicationSetting::query()->first()?->career_recipient_email;
        if (! filled($recipient)) {
            Log::warning('Career application recipient email is not configured.', ['application' => $application->public_id]);

            return;
        }
        try {
            Mail::to($recipient)->send(new CareerApplicationInternalMail($application));
        } catch (\Throwable $exception) {
            Log::warning('Career application internal email failed.', ['application' => $application->public_id, 'exception' => $exception->getMessage()]);
        }
    }
}
