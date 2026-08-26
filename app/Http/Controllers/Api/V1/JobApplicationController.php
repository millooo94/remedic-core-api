<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\JobApplicationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Applications\StoreJobApplicationRequest;
use App\Http\Requests\Api\V1\Applications\UpdateJobApplicationStatusRequest;
use App\Http\Resources\Api\V1\JobApplicationResource;
use App\Models\ApplicationSetting;
use App\Models\JobApplication;
use App\Services\CareerApplicationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class JobApplicationController extends Controller
{
    public function summary(): JsonResponse
    {
        return response()->json([
            'data' => ['unopened_count' => JobApplication::query()->whereNull('first_opened_at')->count()],
        ]);
    }

    public function settings(Request $request): JsonResponse
    {
        $settings = ApplicationSetting::query()->firstOrCreate(['id' => 1]);

        if ($request->isMethod('put')) {
            $settings->update($request->validate([
                'career_recipient_email' => ['nullable', 'email', 'max:190'],
            ]));
        }

        return response()->json([
            'data' => [
                'career_recipient_email' => $settings->career_recipient_email,
                'is_recipient_configured' => filled($settings->career_recipient_email),
            ],
        ]);
    }

    public function index(): AnonymousResourceCollection
    {
        $data = request()->validate([
            'status' => ['nullable', Rule::enum(JobApplicationStatus::class)],
            'application_type' => ['nullable', 'string', 'max:100'],
            'opened' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        return JobApplicationResource::collection(
            JobApplication::query()
                ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
                ->when($data['application_type'] ?? null, fn ($query, $type) => $query->where('application_type_key_snapshot', $type))
                ->when(array_key_exists('opened', $data), fn ($query) => $data['opened'] ? $query->whereNotNull('first_opened_at') : $query->whereNull('first_opened_at'))
                ->latest('submitted_at')
                ->paginate($data['per_page'] ?? 20),
        );
    }

    public function show(JobApplication $jobApplication, CareerApplicationService $service): JobApplicationResource
    {
        return new JobApplicationResource($service->markFirstOpened($jobApplication, request()->user()->id));
    }

    public function updateStatus(UpdateJobApplicationStatusRequest $request, JobApplication $jobApplication): JobApplicationResource
    {
        $jobApplication->update($request->validated());

        return new JobApplicationResource($jobApplication);
    }

    public function storePublic(StoreJobApplicationRequest $request, CareerApplicationService $service): JsonResponse
    {
        $application = $service->submit($request->validated(), $request->file('cv'));

        return response()->json(['data' => ['reference' => $application->public_id, 'submitted_at' => $application->submitted_at?->toIso8601String()]], 201);
    }

    public function downloadCv(JobApplication $jobApplication)
    {
        abort_unless(filled($jobApplication->cv_path) && Storage::disk('local')->exists($jobApplication->cv_path), 404);

        $extension = pathinfo((string) $jobApplication->cv_original_name, PATHINFO_EXTENSION);
        $filename = 'cv-candidatura-'.$jobApplication->public_id.($extension !== '' ? '.'.strtolower($extension) : '');

        return Storage::disk('local')->download($jobApplication->cv_path, $filename);
    }
}
