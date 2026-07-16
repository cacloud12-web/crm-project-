<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\OcrParsedMember */
class OcrParsedMemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sequence_no' => $this->sequence_no,
            'ca_name' => $this->ca_name,
            'membership_no' => $this->membership_no,
            'mobile' => $this->mobile,
            'email' => $this->email,
            'role' => $this->role,
            'overall_confidence' => $this->overall_confidence,
            'field_meta' => $this->field_meta,
        ];
    }
}
