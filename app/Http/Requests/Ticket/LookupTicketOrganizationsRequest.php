<?php

namespace App\Http\Requests\Ticket;

use App\Http\Requests\Concerns\SanitizesUserText;
use Illuminate\Foundation\Http\FormRequest;

class LookupTicketOrganizationsRequest extends FormRequest
{
    use SanitizesUserText;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->sanitizeTextFields(['mobile_number']);
    }

    public function rules(): array
    {
        return [
            'mobile_number' => ['required', 'string', 'max:30'],
        ];
    }
}
