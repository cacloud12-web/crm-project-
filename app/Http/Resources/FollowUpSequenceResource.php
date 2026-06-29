<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FollowUpSequenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'config_id' => $this->config_id,
            'name' => $this->name,
            'is_active' => $this->is_active,
            'sequence_days' => $this->sequence_days,
            'trigger_outcomes' => $this->trigger_outcomes,
            'updated_at' => $this->updated_at,
        ];
    }
}
