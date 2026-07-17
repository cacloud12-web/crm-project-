<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\OcrParsedFirm */
class OcrParsedFirmResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $source = is_array($this->source_data) ? $this->source_data : [];
        $raw = is_array($source['raw'] ?? null) ? $source['raw'] : [];
        $normalized = is_array($source['normalized'] ?? null) ? $source['normalized'] : [];
        $fieldMeta = is_array($this->field_meta) ? $this->field_meta : [];

        return [
            'id' => $this->id,
            'sequence_no' => $this->sequence_no,
            'firm_name' => $this->firm_name,
            'raw_firm_name' => $this->raw_firm_name ?: ($raw['firm_name'] ?? $this->firm_name),
            'normalized_firm_name' => $this->normalized_firm_name ?: ($normalized['firm_name'] ?? null),
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
            'crm_ca_id' => $this->crm_ca_id,
            'ca_id' => $this->crm_ca_id,
            'matched_ca_id' => $this->matched_ca_id,
            'match_status' => $this->match_status,
            'match_confidence' => $this->match_confidence,
            'match_reason' => $this->match_reason,
            'match_candidates' => $this->match_candidates,
            'mapped_at' => $this->mapped_at?->toIso8601String(),
            'overall_confidence' => $this->overall_confidence,
            'page_number' => $this->page_number,
            'field_meta' => $fieldMeta,
            'low_confidence_fields' => $this->lowConfidenceFields($fieldMeta),
            'raw_values' => $raw,
            'normalized_values' => $normalized,
            'members' => $this->when(
                $this->relationLoaded('members'),
                fn () => $this->members
                    ->map(fn ($member) => (new OcrParsedMemberResource($member))->resolve())
                    ->values()
                    ->all(),
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $fieldMeta
     * @return list<string>
     */
    private function lowConfidenceFields(array $fieldMeta): array
    {
        $threshold = (float) config('crm_mapping.field_confidence_review_min', 0.55);
        $low = [];
        foreach ($fieldMeta as $field => $meta) {
            if (! is_array($meta)) {
                continue;
            }
            $confidence = $meta['confidence'] ?? null;
            if ($confidence !== null && (float) $confidence < $threshold) {
                $low[] = (string) $field;
            }
        }

        return $low;
    }
}
