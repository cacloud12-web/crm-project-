<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsMasterRecordLifecycle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoleMasterResource extends JsonResource
{
    use FormatsMasterRecordLifecycle;

    public function toArray(Request $request): array
    {
        return array_merge([
            'role_id' => $this->id,
            'id' => $this->id,
            'role_name' => $this->role_name,
            'description' => $this->description,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ], $this->masterLifecycleFields());
    }
}
