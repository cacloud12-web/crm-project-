<?php

namespace App\Http\Requests\CaMaster;

use App\Rules\CityBelongsToState;
use App\Rules\ValidPhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCaMasterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ca_name' => 'required|string|max:255',
            'firm_name' => 'nullable|string|max:255',
            'mobile_no' => ['nullable', 'string', 'max:20', new ValidPhoneNumber],
            'alternate_mobile_no' => ['nullable', 'string', 'max:20', new ValidPhoneNumber],
            'email_id' => 'nullable|string|max:255|email',
            'gst_no' => 'nullable|string|max:50',
            'pan_no' => 'nullable|string|max:20',
            'google_place_id' => 'nullable|string|max:128',
            'state_id' => 'required|integer|exists:states,state_id',
            'city_id' => ['nullable', 'integer', 'exists:cities,city_id', new CityBelongsToState],
            'source_id' => 'nullable|integer|exists:source_leads,source_id',
            'team_size' => 'nullable|integer|min:1',
            'existing_software' => 'nullable|string|max:255',
            'website' => 'nullable|string|max:255',
            'rating' => 'nullable|integer|min:1|max:5',
            'is_newly_established' => 'nullable|in:yes,no,0,1,true,false',
            'status' => ['nullable', 'string', 'max:255', Rule::in(config('crm_statuses.allowed', []))],
            'lead_tags' => 'nullable|array',
            'lead_tags.*' => ['string', Rule::in(config('crm_leads.allowed_tags', []))],
            'priority' => ['nullable', 'string', Rule::in(config('crm_leads.priorities', []))],
            'research_status' => ['nullable', 'string', Rule::in(config('crm_leads.research_statuses', []))],
            'executive_id' => 'nullable|integer|exists:employees,employee_id',
            'created_by_employee_id' => 'nullable|integer|exists:employees,employee_id',
        ];
    }
}
