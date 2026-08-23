<?php

namespace App\Http\Requests\Api\V1\Services;

use App\Models\Professional;
use App\Models\Service;
use App\Models\Specialization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category_id' => ['nullable', 'integer', 'exists:service_categories,id'],
            'category_name' => ['nullable', 'string', 'max:190'],
            'canonical_name' => ['nullable', 'string', 'max:190'],
            'display_name' => ['required', 'string', 'max:190'],
            'importo_prestazione' => ['nullable', 'integer', 'min:0'],
            'default_duration_minutes' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string'],
            'featured_image_path' => ['prohibited'],
            'icon_path' => ['prohibited'],
            'specialization_ids' => ['required', 'array', 'min:1'],
            'specialization_ids.*' => ['required', 'integer', 'distinct', 'exists:specializations,id'],
            'aliases' => ['sometimes', 'array'],
            'aliases.*.alias_name' => ['required', 'string', 'max:190'],
            'aliases.*.source_label' => ['nullable', 'string', 'max:120'],
            'professional_services' => ['required', 'array', 'min:1'],
            'professional_services.*.professional_id' => ['required', 'exists:professionals,id', 'distinct'],
            'professional_services.*.duration_minutes' => ['nullable', 'integer', 'min:1'],
            'professional_services.*.price_amount' => ['nullable', 'numeric', 'min:0'],
            'professional_services.*.is_visible_public' => ['sometimes', 'boolean'],
            'professional_services.*.is_bookable_online' => ['sometimes', 'boolean'],
            'professional_services.*.source_platform' => ['nullable', 'string', 'max:120'],
            'professional_services.*.source_notes' => ['nullable', 'string'],
            'professional_services.*.is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'importo_prestazione.integer' => "L'importo prestazione deve essere un numero intero senza centesimi.",
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $specializationIds = collect($this->input('specialization_ids', []))
                ->filter(fn ($id) => is_numeric($id))
                ->map(fn ($id) => (int) $id)
                ->values();
            $links = $this->input('professional_services', []);

            if ($specializationIds->isEmpty()) {
                $validator->errors()->add('specialization_ids', 'Seleziona almeno una specializzazione.');

                return;
            }

            if (! is_array($links) || count($links) === 0) {
                return;
            }

            $primarySpecialization = Specialization::query()->find($specializationIds->first());
            $resolvedCategoryName = trim((string) $primarySpecialization?->name);
            $normalizedCategoryName = mb_strtolower($resolvedCategoryName);

            $professionalIds = collect($links)
                ->pluck('professional_id')
                ->filter(fn ($id) => is_numeric($id))
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values();

            if ($professionalIds->isEmpty()) {
                return;
            }

            $allowedIds = Professional::query()
                ->whereIn('id', $professionalIds)
                ->where(function ($professionalQuery) use ($specializationIds, $normalizedCategoryName): void {
                    $professionalQuery
                        ->whereHas('specializations', fn ($specializationQuery) => $specializationQuery->whereIn('specializations.id', $specializationIds))
                        ->orWhereRaw('LOWER(TRIM(area_name)) = ?', [$normalizedCategoryName])
                        ->orWhereHas('areas', fn ($areaQuery) => $areaQuery->whereRaw('LOWER(TRIM(name)) = ?', [$normalizedCategoryName]));
                })
                ->pluck('id');

            $invalidIds = $professionalIds->diff($allowedIds)->values();
            if ($invalidIds->isNotEmpty()) {
                $validator->errors()->add(
                    'professional_services',
                    'I professionisti selezionati devono appartenere ad almeno una delle specializzazioni scelte.',
                );
            }

            $displayName = trim((string) $this->input('display_name', ''));
            if ($displayName === '') {
                return;
            }

            $existingServiceId = (int) ($this->route('service')?->id ?? 0);
            $duplicateServiceQuery = Service::query()
                ->when(
                    $existingServiceId > 0,
                    fn ($query) => $query->whereKeyNot($existingServiceId),
                )
                ->whereRaw('LOWER(TRIM(display_name)) = ?', [mb_strtolower($displayName)])
                ->whereHas(
                    'category',
                    fn ($categoryQuery) => $categoryQuery->whereRaw('LOWER(TRIM(name)) = ?', [$normalizedCategoryName]),
                );

            if ($duplicateServiceQuery->exists()) {
                $validator->errors()->add(
                    'display_name',
                    'Esiste gia una prestazione con questo nome nella specializzazione selezionata. Modifica quella esistente per aggiungere altri professionisti.',
                );
            }
        });
    }
}
