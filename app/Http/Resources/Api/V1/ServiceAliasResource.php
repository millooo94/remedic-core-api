<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServiceAliasResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'alias_name' => $this->alias_name,
            'alias_slug' => $this->alias_slug,
            'source_label' => $this->source_label,
        ];
    }
}
