<?php

namespace App\Http\Requests\Api\V1\Admin\ConsentServices;

use App\Http\Requests\Api\V1\Admin\BackofficeIndexRequest;
use Illuminate\Validation\Rule;

class ConsentServiceIndexRequest extends BackofficeIndexRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'consent_category_id' => ['sometimes', 'nullable', 'integer', 'exists:consent_categories,id'],
            'sort' => ['sometimes', 'nullable', Rule::in(['name', 'provider', 'execution_mode', 'updated_at'])],
        ]);
    }
}
