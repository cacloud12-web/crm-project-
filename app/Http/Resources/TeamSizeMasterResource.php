<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeamSizeMasterResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'team_size_id' => $this->id,
            'id' => $this->id,
            'team_size_min' => $this->team_size_min,
            'team_size_max' => $this->team_size_max,
            'team_size_label' => $this->team_size_label,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
