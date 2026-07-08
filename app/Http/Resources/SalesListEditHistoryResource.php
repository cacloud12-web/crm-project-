<?php

namespace App\Http\Resources;

use App\Services\Sales\SalesListService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalesListEditHistoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'field_name' => $this->field_name,
            'field_label' => SalesListService::fieldLabel((string) $this->field_name),
            'old_value' => $this->old_value,
            'new_value' => $this->new_value,
            'edited_by' => $this->user?->name,
            'edited_at' => $this->edited_at?->toIso8601String(),
        ];
    }
}
