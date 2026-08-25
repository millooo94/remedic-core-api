<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Applications\StoreJobApplicationRequest;
use App\Http\Requests\Api\V1\Applications\UpdateJobApplicationStatusRequest;
use App\Http\Resources\Api\V1\JobApplicationResource;
use App\Models\ApplicationType;
use App\Models\JobApplication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;

class JobApplicationController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return JobApplicationResource::collection(JobApplication::query()->latest('submitted_at')->paginate(50));
    }

    public function show(JobApplication $jobApplication): JobApplicationResource
    {
        return new JobApplicationResource($jobApplication);
    }

    public function updateStatus(UpdateJobApplicationStatusRequest $request, JobApplication $jobApplication): JobApplicationResource
    {
        $jobApplication->update($request->validated());

        return new JobApplicationResource($jobApplication);
    }

    public function storePublic(StoreJobApplicationRequest $request): JsonResponse
    {
        $type = ApplicationType::query()->whereKey($request->integer('application_type_id'))->where('is_active', true)->firstOrFail();
        $attributes = Arr::except($request->validated(), 'cv');
        $cv = $request->file('cv');
        if ($cv !== null) {
            $attributes['cv_path'] = $cv->store('job-applications/cv', 'local');
            $attributes['cv_original_name'] = $cv->getClientOriginalName();
        }
        $application = JobApplication::create([...$attributes, 'application_type_name_snapshot' => $type->name, 'status' => 'new', 'submitted_at' => now()]);

        return response()->json(['data' => ['id' => $application->id, 'submitted_at' => $application->submitted_at?->toIso8601String()]], 201);
    }

    public function downloadCv(JobApplication $jobApplication)
    {
        abort_unless(filled($jobApplication->cv_path) && Storage::disk('local')->exists($jobApplication->cv_path), 404);

        $extension = pathinfo((string) $jobApplication->cv_original_name, PATHINFO_EXTENSION);
        $filename = 'cv-candidatura-'.$jobApplication->id.($extension !== '' ? '.'.strtolower($extension) : '');

        return Storage::disk('local')->download($jobApplication->cv_path, $filename);
    }
}
