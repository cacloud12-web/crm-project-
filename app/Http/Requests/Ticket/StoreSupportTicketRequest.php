<?php

namespace App\Http\Requests\Ticket;

use App\Http\Requests\Concerns\SanitizesUserText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSupportTicketRequest extends FormRequest
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
            'customer_name' => ['required', 'string', 'max:255'],
            'raised_by_name' => ['nullable', 'string', 'max:255'],
            'mobile_number' => ['required', 'string', 'max:30'],
            'verification_correlation_id' => ['required', 'uuid'],
            // Never accept org/email from the browser — server reads verified lookup.
            'organization_number' => ['prohibited'],
            'organization_name' => ['prohibited'],
            'email' => ['prohibited'],
            'problem_type' => ['required', 'string', Rule::in(config('crm_tickets.problem_types', []))],
            'priority' => ['nullable', 'string', Rule::in(config('crm_tickets.priorities', []))],
            'status' => ['nullable', 'string', Rule::in(config('crm_tickets.statuses', []))],
            'description' => ['required', 'string'],
            'admin_remarks' => ['nullable', 'string'],
            'assigned_to_employee_id' => ['nullable', 'integer', 'exists:employees,employee_id'],
            'created_via' => ['nullable', 'string', Rule::in(config('crm_tickets.created_via_values', []))],
        ];
    }
}
