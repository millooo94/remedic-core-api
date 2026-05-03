<?php

namespace App\Http\Requests\Api\V1\PerformanceRecords;

class StorePerformanceRecordRequest extends PerformanceRecordUpsertRequest
{
    public function rules(): array
    {
        return parent::rules();
    }
}
