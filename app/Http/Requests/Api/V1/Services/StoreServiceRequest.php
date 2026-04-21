<?php

namespace App\Http\Requests\Api\V1\Services;

use App\Models\Professional;
use App\Models\ServiceCategory;
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
            'default_duration_minutes' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string'],
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

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $categoryId = $this->input('category_id');
            $categoryNameInput = trim((string) $this->input('category_name', ''));
            $links = $this->input('professional_services', []);

            if (!is_numeric($categoryId) && $categoryNameInput === '') {
                $validator->errors()->add('category_name', "L'area e obbligatoria.");
                return;
            }

            if (!is_array($links) || count($links) === 0) {
                return;
            }

            $category = is_numeric($categoryId) ? ServiceCategory::query()->find($categoryId) : null;
            $resolvedCategoryName = trim((string) ($category?->name ?: $categoryNameInput));
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
                ->where(function ($professionalQuery) use ($category, $normalizedCategoryName): void {
                    if ($category !== null) {
                        $professionalQuery->whereHas('areas', fn ($areaQuery) => $areaQuery->whereKey($category->id));
                    }

                    $professionalQuery
                        ->orWhereRaw('LOWER(TRIM(area_name)) = ?', [$normalizedCategoryName])
                        ->orWhereHas('areas', fn ($areaQuery) => $areaQuery->whereRaw('LOWER(TRIM(name)) = ?', [$normalizedCategoryName]));
                })
                ->pluck('id');

            $invalidIds = $professionalIds->diff($allowedIds)->values();
            if ($invalidIds->isNotEmpty()) {
                $validator->errors()->add(
                    'professional_services',
                    "I professionisti selezionati devono appartenere all'area scelta.",
                );
            }
        });
    }
}
