<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicApplicationTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['key' => $this->key, 'label' => $this->name];
    }
}
