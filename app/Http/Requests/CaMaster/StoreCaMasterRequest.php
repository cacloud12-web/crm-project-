<?php

namespace App\Http\Requests\CaMaster;

use App\Rules\CityBelongsToState;
use App\Rules\ValidMobileNumber;
use Illuminate\Foundation\Http\FormRequest;

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
            'mobile_no' => ['nullable', 'string', 'max:20', new ValidMobileNumber],
            'alternate_mobile_no' => ['nullable', 'string', 'max:20', new ValidMobileNumber],
            'email_id' => 'nullable|email|max:255',
            'gst_no' => 'nullable|string|max:50',
            'state_id' => 'required|integer|exists:states,state_id',
            'city_id' => ['nullable', 'integer', 'exists:cities,city_id', new CityBelongsToState],
            'source_id' => 'nullable',
            'team_size' => 'nullable|integer|min:1',
            'existing_software' => 'nullable|string|max:255',
            'website' => 'nullable|string|max:255',
            'rating' => 'nullable|integer|min:1|max:5',
            'is_newly_established' => 'nullable|in:yes,no,0,1,true,false',
            'status' => 'nullable|string|max:255',
        ];
    }
}
