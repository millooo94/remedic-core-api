<?php

namespace App\Http\Requests\Api\V1\Professionals;

use App\Enums\ProfessionalSubjectType;
use App\Models\Professional;

class UpdateProfessionalRequest extends StoreProfessionalRequest
{
    protected function prepareForValidation(): void
    {
        /** @var Professional|null $professional */
        $professional = $this->route('professional');

        if ($professional) {
            $currentAreaNames = $professional->areas()->pluck('name')->filter()->values()->all();
            if ($currentAreaNames === [] && ! empty($professional->area_name)) {
                $currentAreaNames = [(string) $professional->area_name];
            }

            $hasAreaNames = $this->has('area_names') || $this->has('area_names[]');

            $this->merge([
                'subject_type' => $this->input('subject_type', $professional->subject_type?->value ?? ProfessionalSubjectType::Individual->value),
                'first_name' => $this->input('first_name', $professional->first_name),
                'last_name' => $this->input('last_name', $professional->last_name),
                'company_name' => $this->exists('company_name') ? $this->input('company_name') : $professional->company_name,
                'area_name' => $this->input('area_name', $professional->area_name),
                'area_names' => $hasAreaNames ? $this->rawAreaNames() : $currentAreaNames,
                'email' => $this->exists('email') ? $this->input('email') : $professional->email,
                'iban' => $this->exists('iban') ? $this->input('iban') : $professional->iban,
                'is_active' => $this->input('is_active', $professional->is_active),
                'notes' => $this->exists('notes') ? $this->input('notes') : $professional->notes,
            ]);
        }

        parent::prepareForValidation();
    }
}
