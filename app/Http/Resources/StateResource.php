<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'state_id' => $this->state_id,
            'state_name' => $this->state_name,
            'cities_count' => $this->whenCounted('cities'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
