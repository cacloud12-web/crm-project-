<?php

namespace App\Http\Requests\CaMaster;

use App\Rules\ValidMobileNumber;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCaMasterContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'mobile_no' => ['required', 'string', 'max:20', new ValidMobileNumber(required: true)],
            'alternate_mobile_no' => ['nullable', 'string', 'max:20', new ValidMobileNumber],
            'email_id' => 'nullable|email|max:255',
            'website' => 'nullable|string|max:255',
        ];
    }
}
