<?php

namespace App\Http\Requests\CaMaster;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCaMasterStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in([
                'New', 'Hot', 'Warm', 'Pipeline', 'Demo Scheduled', 'Active', 'Inactive', 'Lost',
            ])],
        ];
    }
}
