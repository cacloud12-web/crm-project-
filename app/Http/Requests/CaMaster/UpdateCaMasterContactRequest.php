<?php

namespace App\Http\Requests\CaMaster;

use App\Rules\ValidPhoneNumber;
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
            'mobile_no' => ['nullable', 'string', 'max:20', new ValidPhoneNumber],
            'alternate_mobile_no' => ['nullable', 'string', 'max:20', new ValidPhoneNumber],
            'email_id' => 'nullable|string|max:255|email',
            'website' => 'nullable|string|max:255',
        ];
    }
}
