<?php

namespace App\Http\Requests\Ticket;

use App\Http\Requests\Concerns\SanitizesUserText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeTicketStatusRequest extends FormRequest
{
    use SanitizesUserText;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->sanitizeTextFields(['notes']);
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(config('crm_tickets.statuses', []))],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
