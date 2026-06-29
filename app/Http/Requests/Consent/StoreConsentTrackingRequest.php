<?php

namespace App\Http\Requests\Consent;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreConsentTrackingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ca_id' => 'required|integer|exists:ca_masters,ca_id',
            'consent_type' => ['required', 'string', Rule::in(['WhatsApp', 'Email', 'SMS'])],
            'consent_status' => ['required', 'string', Rule::in(['Yes', 'No'])],
            'consent_date' => 'nullable|date',
            'performed_by' => 'nullable|string|max:255',
        ];
    }
}
