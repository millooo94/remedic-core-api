<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Media\UploadMasterIconRequest;
use App\Http\Requests\Api\V1\Media\UploadMasterImageRequest;
use App\Http\Resources\Api\V1\CheckupResource;
use App\Http\Resources\Api\V1\ProfessionalResource;
use App\Http\Resources\Api\V1\ServiceResource;
use App\Http\Resources\Api\V1\SpecializationResource;
use App\Models\Checkup;
use App\Models\Professional;
use App\Models\Service;
use App\Models\Specialization;
use App\Services\CheckupCatalogService;
use App\Services\ManagedMediaService;

class EntityMediaController extends Controller
{
    public function __construct(
        private readonly ManagedMediaService $media,
        private readonly CheckupCatalogService $checkups,
    ) {}

    public function uploadProfessionalImage(UploadMasterImageRequest $request, Professional $professional): ProfessionalResource
    {
        $this->media->replace(
            $professional,
            'avatar_path',
            $request->file('image'),
            "professionals/{$professional->id}",
            ["professional-avatars/{$professional->id}"],
        );

        return new ProfessionalResource($professional->refresh()->load(['areas', 'specializations', 'publicProfile']));
    }

    public function deleteProfessionalImage(Professional $professional): ProfessionalResource
    {
        $this->media->delete($professional, 'avatar_path', [
            "professionals/{$professional->id}",
            "professional-avatars/{$professional->id}",
        ]);

        return new ProfessionalResource($professional->refresh()->load(['areas', 'specializations', 'publicProfile']));
    }

    public function uploadSpecializationImage(UploadMasterImageRequest $request, Specialization $specialization): SpecializationResource
    {
        $this->media->replace($specialization, 'featured_image_path', $request->file('image'), "specializations/{$specialization->id}/images");

        return $this->specializationResource($specialization);
    }

    public function deleteSpecializationImage(Specialization $specialization): SpecializationResource
    {
        $this->media->delete($specialization, 'featured_image_path', ["specializations/{$specialization->id}/images"]);

        return $this->specializationResource($specialization);
    }

    public function uploadSpecializationIcon(UploadMasterIconRequest $request, Specialization $specialization): SpecializationResource
    {
        $this->media->replace(
            $specialization,
            'icon_path',
            $request->file('icon'),
            "specializations/{$specialization->id}/icons",
            ['specializations/icons'],
        );

        return $this->specializationResource($specialization);
    }

    public function deleteSpecializationIcon(Specialization $specialization): SpecializationResource
    {
        $this->media->delete($specialization, 'icon_path', [
            "specializations/{$specialization->id}/icons",
            'specializations/icons',
        ]);

        return $this->specializationResource($specialization);
    }

    public function uploadServiceImage(UploadMasterImageRequest $request, Service $service): ServiceResource
    {
        $this->media->replace($service, 'featured_image_path', $request->file('image'), "services/{$service->id}/images");

        return $this->serviceResource($service);
    }

    public function deleteServiceImage(Service $service): ServiceResource
    {
        $this->media->delete($service, 'featured_image_path', ["services/{$service->id}/images"]);

        return $this->serviceResource($service);
    }

    public function uploadCheckupImage(UploadMasterImageRequest $request, Checkup $checkup): CheckupResource
    {
        $this->media->replace($checkup, 'featured_image_path', $request->file('image'), "checkups/{$checkup->id}/images");

        return $this->checkupResource($checkup);
    }

    public function deleteCheckupImage(Checkup $checkup): CheckupResource
    {
        $this->media->delete($checkup, 'featured_image_path', ["checkups/{$checkup->id}/images"]);

        return $this->checkupResource($checkup);
    }

    public function uploadCheckupIcon(UploadMasterIconRequest $request, Checkup $checkup): CheckupResource
    {
        $this->media->replace($checkup, 'icon_path', $request->file('icon'), "checkups/{$checkup->id}/icons");

        return $this->checkupResource($checkup);
    }

    public function deleteCheckupIcon(Checkup $checkup): CheckupResource
    {
        $this->media->delete($checkup, 'icon_path', ["checkups/{$checkup->id}/icons"]);

        return $this->checkupResource($checkup);
    }

    private function specializationResource(Specialization $specialization): SpecializationResource
    {
        return new SpecializationResource($specialization->refresh()->loadCount(['professionals', 'services']));
    }

    private function serviceResource(Service $service): ServiceResource
    {
        return new ServiceResource($service->refresh()->load(['category', 'aliases', 'professionalServices.professional.specializations', 'specializations']));
    }

    private function checkupResource(Checkup $checkup): CheckupResource
    {
        return new CheckupResource($this->checkups->loadForResource($checkup->refresh(), true));
    }
}
