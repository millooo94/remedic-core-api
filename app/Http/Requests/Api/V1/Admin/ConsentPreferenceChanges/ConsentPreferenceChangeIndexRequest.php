<?php

namespace App\Http\Requests\Api\V1\Admin\ConsentPreferenceChanges;

use App\Enums\ConsentEventType;
use App\Http\Requests\Api\V1\Admin\BackofficeIndexRequest;
use Illuminate\Validation\Rule;

class ConsentPreferenceChangeIndexRequest extends BackofficeIndexRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'consent_record_id' => ['sometimes', 'nullable', 'integer', 'exists:consent_records,id'],
            'event_type' => ['sometimes', 'nullable', Rule::enum(ConsentEventType::class)],
            'sort' => ['sometimes', 'nullable', Rule::in(['created_at'])],
        ]);
    }
}
