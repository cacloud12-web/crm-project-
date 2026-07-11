<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsMasterRecordLifecycle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SourceLeadResource extends JsonResource
{
    use FormatsMasterRecordLifecycle;

    public function toArray(Request $request): array
    {
        return array_merge([
            'source_id' => $this->source_id,
            'source_name' => $this->source_name,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ], $this->masterLifecycleFields());
    }
}
