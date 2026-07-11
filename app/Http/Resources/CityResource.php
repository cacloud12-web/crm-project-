<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsMasterRecordLifecycle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CityResource extends JsonResource
{
    use FormatsMasterRecordLifecycle;

    public function toArray(Request $request): array
    {
        return array_merge([
            'city_id' => $this->city_id,
            'city_name' => $this->city_name,
            'state_id' => $this->state_id,
            'state' => $this->state?->state_name,
            'state_name' => $this->state?->state_name,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ], $this->masterLifecycleFields());
    }
}
