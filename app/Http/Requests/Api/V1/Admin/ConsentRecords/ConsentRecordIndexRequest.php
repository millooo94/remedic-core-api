<?php

namespace App\Http\Requests\Api\V1\Admin\ConsentRecords;

use App\Http\Requests\Api\V1\Admin\BackofficeIndexRequest;
use Illuminate\Validation\Rule;

class ConsentRecordIndexRequest extends BackofficeIndexRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'consent_policy_version_id' => ['sometimes', 'nullable', 'integer', 'exists:consent_policy_versions,id'],
            'status' => ['sometimes', 'nullable', Rule::in(['accepted_all', 'rejected_all', 'customized', 'withdrawn'])],
            'sort' => ['sometimes', 'nullable', Rule::in(['created_at', 'consented_at'])],
        ]);
    }
}
