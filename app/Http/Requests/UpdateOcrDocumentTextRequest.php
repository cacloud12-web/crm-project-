<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOcrDocumentTextRequest extends FormRequest
{
    public function authorize(): bool
    {
        $document = $this->route('ocrDocument');

        return $document && $this->user()?->can('update', $document);
    }

    public function rules(): array
    {
        return [
            'corrected_text' => ['required', 'string', 'max:200000'],
        ];
    }
}
