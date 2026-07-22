<?php

namespace App\Http\Requests\Ticket;

use Illuminate\Foundation\Http\FormRequest;

class StoreTicketAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $maxKb = max(1, (int) config('crm_tickets.max_attachment_mb', 20)) * 1024;
        $mimes = implode(',', config('crm_tickets.allowed_mime_types', []));
        $extensions = implode(',', config('crm_tickets.allowed_extensions', []));

        return [
            'attachment' => [
                'required',
                'file',
                'max:'.$maxKb,
                'mimetypes:'.$mimes,
                'mimes:'.$extensions,
            ],
            'ticket_comment_id' => ['nullable', 'integer', 'exists:ticket_comments,id'],
        ];
    }
}
