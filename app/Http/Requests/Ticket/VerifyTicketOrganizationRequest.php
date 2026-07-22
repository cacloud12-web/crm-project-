<?php

namespace App\Http\Requests\Ticket;

use App\Http\Requests\Concerns\SanitizesUserText;
use Illuminate\Foundation\Http\FormRequest;

class VerifyTicketOrganizationRequest extends FormRequest
{
    use SanitizesUserText;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->sanitizeTextFields([
            'mobile_number',
            'organization_number',
            'correlation_id',
        ]);
    }

    public function rules(): array
    {
        return [
            'mobile_number' => ['required', 'string', 'max:30'],
            'organization_number' => ['required', 'string', 'max:64'],
            'correlation_id' => ['required', 'uuid'],
            // Never accept trusted identity fields from the browser.
            'organization_name' => ['prohibited'],
            'email' => ['prohibited'],
            'verified_email' => ['prohibited'],
            'verification_status' => ['prohibited'],
        ];
    }
}
