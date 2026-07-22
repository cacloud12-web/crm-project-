<?php

namespace App\Http\Requests\Ticket;

use App\Http\Requests\Concerns\SanitizesUserText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSupportTicketRequest extends FormRequest
{
    use SanitizesUserText;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->sanitizeTextFields([
            'customer_name',
            'raised_by_name',
            'mobile_number',
            'description',
            'admin_remarks',
        ]);
    }

    public function rules(): array
    {
        return [
            'customer_name' => ['sometimes', 'required', 'string', 'max:255'],
            'raised_by_name' => ['nullable', 'string', 'max:255'],
            'mobile_number' => ['sometimes', 'required', 'string', 'max:30'],
            'organization_number' => ['prohibited'],
            'organization_name' => ['prohibited'],
            'email' => ['prohibited'],
            'verification_correlation_id' => ['prohibited'],
            'problem_type' => ['sometimes', 'string', Rule::in(config('crm_tickets.problem_types', []))],
            'priority' => ['sometimes', 'string', Rule::in(config('crm_tickets.priorities', []))],
            'status' => ['sometimes', 'string', Rule::in(config('crm_tickets.statuses', []))],
            'description' => ['sometimes', 'required', 'string'],
            'admin_remarks' => ['nullable', 'string'],
            'assigned_to_employee_id' => ['nullable', 'integer', 'exists:employees,employee_id'],
        ];
    }
}
