<?php

namespace App\Http\Requests\Dnd;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDndManagementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ca_id' => 'required|integer|exists:ca_masters,ca_id',
            'mobile_no' => 'nullable|string|max:20',
            'email_id' => 'nullable|email|max:255',
            'dnd_type' => ['required', 'string', Rule::in(['WA', 'Email', 'SMS', 'All'])],
            'reason' => 'nullable|string|max:500',
            'added_by' => 'nullable|string|max:255',
            'added_at' => 'nullable|date',
        ];
    }
}
