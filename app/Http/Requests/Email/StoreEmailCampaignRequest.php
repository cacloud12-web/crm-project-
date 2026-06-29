<?php

namespace App\Http\Requests\Email;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmailCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'campaign_name' => 'required|string|max:255',
            'campaign_type' => 'required|string|max:255',
            'audience_mode' => [
                'required',
                'string',
                Rule::in(['selected_leads', 'all_leads', 'city', 'state', 'source', 'rating', 'team_size', 'existing_software']),
            ],
            'subject' => 'required|string|max:255',
            'body_template' => 'required|string|max:10000',
            'scheduled_at' => 'nullable|date',
            'ca_ids' => 'required_if:audience_mode,selected_leads|array|min:1',
            'ca_ids.*' => 'integer|exists:ca_masters,ca_id',
            'city_id' => 'required_if:audience_mode,city|nullable|integer|exists:cities,city_id',
            'state_id' => 'required_if:audience_mode,state|nullable|integer|exists:states,state_id',
            'source_id' => 'required_if:audience_mode,source|nullable|integer|exists:source_leads,source_id',
            'rating' => 'required_if:audience_mode,rating|nullable|integer|min:1|max:5',
            'team_size' => 'required_if:audience_mode,team_size|nullable|integer|min:1',
            'existing_software' => 'required_if:audience_mode,existing_software|nullable|string|max:255',
            'performed_by' => 'nullable|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'ca_ids.required_if' => 'Select at least one lead for the selected audience.',
            'city_id.required_if' => 'Select a city for the audience filter.',
            'state_id.required_if' => 'Select a state for the audience filter.',
            'source_id.required_if' => 'Select a lead source for the audience filter.',
            'rating.required_if' => 'Select a rating for the audience filter.',
            'team_size.required_if' => 'Enter a team size for the audience filter.',
            'existing_software.required_if' => 'Select existing software for the audience filter.',
        ];
    }
}
