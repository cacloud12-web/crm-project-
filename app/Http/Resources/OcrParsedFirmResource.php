<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\OcrParsedFirm */
class OcrParsedFirmResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sequence_no' => $this->sequence_no,
            'firm_name' => $this->firm_name,
            'firm_type' => $this->firm_type,
            'frn' => $this->frn,
            'gst_no' => $this->gst_no,
            'pan_no' => $this->pan_no,
            'address' => $this->address,
            'city' => $this->city,
            'state' => $this->state,
            'pincode' => $this->pincode,
            'phone' => $this->phone,
            'email' => $this->email,
            'website' => $this->website,
            'review_status' => $this->review_status,
            'overall_confidence' => $this->overall_confidence,
            'page_number' => $this->page_number,
            'field_meta' => $this->when($request->boolean('include_field_meta'), $this->field_meta),
            'members' => $this->when(
                $this->relationLoaded('members'),
                fn () => $this->members
                    ->map(fn ($member) => (new OcrParsedMemberResource($member))->resolve())
                    ->values()
                    ->all(),
            ),
        ];
    }
}
