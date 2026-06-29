<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CaMasterResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'ca_id' => $this->ca_id,
            'ca_name' => $this->ca_name,
            'firm_name' => $this->firm_name,
            'mobile_no' => $this->mobile_no,
            'alternate_mobile_no' => $this->alternate_mobile_no,
            'email_id' => $this->email_id,
            'city_id' => $this->city_id,
            'state_id' => $this->state_id,
            'source_id' => $this->source_id,
            'city' => $this->city?->city_name,
            'city_name' => $this->city?->city_name,
            'state' => $this->state?->state_name,
            'state_name' => $this->state?->state_name,
            'source' => $this->sourceLead?->source_name,
            'source_name' => $this->sourceLead?->source_name,
            'team_size' => $this->team_size,
            'existing_software' => $this->existing_software,
            'website' => $this->website,
            'gst_no' => $this->gst_no,
            'rating' => $this->rating,
            'is_newly_established' => (bool) $this->is_newly_established,
            'status' => $this->status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
