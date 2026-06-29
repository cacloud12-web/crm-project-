<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoleMasterResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'role_id' => $this->id,
            'id' => $this->id,
            'role_name' => $this->role_name,
            'description' => $this->description,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
