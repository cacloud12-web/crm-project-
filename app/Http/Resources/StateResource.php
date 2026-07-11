<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsMasterRecordLifecycle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StateResource extends JsonResource
{
    use FormatsMasterRecordLifecycle;

    public function toArray(Request $request): array
    {
        return array_merge([
            'state_id' => $this->state_id,
            'state_name' => $this->state_name,
            'cities_count' => $this->whenCounted('cities'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ], $this->masterLifecycleFields());
    }
}
