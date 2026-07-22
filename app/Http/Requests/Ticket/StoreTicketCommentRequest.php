<?php

namespace App\Http\Requests\Ticket;

use App\Http\Requests\Concerns\SanitizesUserText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTicketCommentRequest extends FormRequest
{
    use SanitizesUserText;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->sanitizeTextFields(['body', 'author_name']);
    }

    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:10000'],
            'author_name' => ['nullable', 'string', 'max:255'],
            'author_type' => ['nullable', 'string', Rule::in(config('crm_tickets.author_types', []))],
            'comment_type' => ['nullable', 'string', Rule::in(config('crm_tickets.comment_types', []))],
            'visibility' => ['nullable', 'string', Rule::in(config('crm_tickets.comment_visibilities', []))],
            'is_internal' => ['nullable', 'boolean'],
        ];
    }
}
