<?php

namespace App\Http\Requests\Ticket;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InboundCaCloudDeskTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Prefer numeric LawSeva id; ticket_id (e.g. TCK-1234) is secondary display id.
        $externalId = $this->input('external_ticket_id')
            ?? $this->input('id')
            ?? $this->input('ticket_id');

        $organizationNumber = $this->input('organization_number')
            ?? $this->input('organization');

        $organizationName = $this->input('organization_name')
            ?? $this->input('organization_data.name');

        $partnerName = $this->input('raised_by_name')
            ?? $this->input('partner_name')
            ?? data_get($this->input('partner_data'), 'first_name')
            ?? data_get($this->input('partner_data'), 'name');

        $mobile = $this->input('mobile_number')
            ?? $this->input('partner_phone')
            ?? data_get($this->input('partner_data'), 'phone')
            ?? data_get($this->input('partner_data'), 'mobile');
        if (! filled($mobile)) {
            $mobile = '0000000000';
        }

        $email = $this->input('email')
            ?? $this->input('partner_email')
            ?? data_get($this->input('partner_data'), 'email');

        $customerName = $this->input('customer_name')
            ?? $organizationName
            ?? $partnerName
            ?? 'LawSeva Customer';

        $description = $this->input('description')
            ?? $this->input('summery')
            ?? $this->input('summary');

        $category = $this->input('category');
        $problemType = $this->input('problem_type');
        if (! filled($problemType)) {
            $problemType = $this->mapCategoryToProblemType(is_string($category) ? $category : null);
        }

        $this->merge([
            'external_ticket_id' => $externalId !== null ? (string) $externalId : null,
            'organization_number' => $organizationNumber !== null ? (string) $organizationNumber : null,
            'organization_name' => $organizationName !== null ? (string) $organizationName : null,
            'customer_name' => $customerName !== null ? (string) $customerName : null,
            'raised_by_name' => $partnerName !== null ? (string) $partnerName : null,
            'mobile_number' => $mobile !== null ? (string) $mobile : null,
            'email' => $email !== null ? (string) $email : null,
            'description' => $description !== null ? (string) $description : null,
            'problem_type' => $problemType,
            'priority' => $this->input('priority', 'normal'),
            'status' => $this->normalizeStatus($this->input('status'), $this->input('is_solved'), $this->input('is_rejected')),
            'partner_id' => $this->input('partner') ?? $this->input('partner_id') ?? data_get($this->input('partner_data'), 'id'),
            'category' => is_string($category) ? $category : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'external_ticket_id' => ['required', 'string', 'max:128'],
            'organization_number' => ['required', 'string', 'max:64'],
            'organization_name' => ['required', 'string', 'max:255'],
            'customer_name' => ['required', 'string', 'max:255'],
            'raised_by_name' => ['nullable', 'string', 'max:255'],
            'mobile_number' => ['required', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:190'],
            'description' => ['required', 'string'],
            'problem_type' => ['required', Rule::in(config('crm_tickets.problem_types', ['issue', 'improvement', 'new_feature']))],
            'priority' => ['nullable', Rule::in(config('crm_tickets.priorities', ['low', 'normal', 'high', 'urgent']))],
            'status' => ['nullable', Rule::in(config('crm_tickets.statuses', ['open', 'under_review', 'closed']))],
            'category' => ['nullable', 'string', 'max:255'],
            'partner_id' => ['nullable'],
            'documents' => ['nullable', 'array'],
            'summery' => ['nullable', 'string'],
            'summary' => ['nullable', 'string'],
            'admin_remarks' => ['nullable', 'string'],
            'external_updated_at' => ['nullable', 'date'],
            'created_at' => ['nullable', 'date'],
            'modified_at' => ['nullable', 'date'],
        ];
    }

    private function mapCategoryToProblemType(?string $category): string
    {
        $normalized = strtolower(trim((string) $category));
        if ($normalized === '') {
            return 'issue';
        }
        if (str_contains($normalized, 'feature') || str_contains($normalized, 'enhancement')) {
            return 'new_feature';
        }
        if (str_contains($normalized, 'improve') || str_contains($normalized, 'suggestion')) {
            return 'improvement';
        }

        return 'issue';
    }

    private function normalizeStatus(mixed $status, mixed $isSolved, mixed $isRejected): string
    {
        if (is_string($status) && $status !== '') {
            $normalized = strtolower(trim($status));
            if (in_array($normalized, config('crm_tickets.statuses', []), true)) {
                return $normalized;
            }
            if (in_array($normalized, ['solved', 'resolved', 'closed', 'done'], true)) {
                return 'closed';
            }
            if (in_array($normalized, ['review', 'in_progress', 'under review'], true)) {
                return 'under_review';
            }
        }

        if (filter_var($isSolved, FILTER_VALIDATE_BOOLEAN) || filter_var($isRejected, FILTER_VALIDATE_BOOLEAN)) {
            return 'closed';
        }

        return 'open';
    }
}
