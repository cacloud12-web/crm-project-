<?php

namespace App\Http\Requests\Auth;

use App\Rules\ValidLoginEmailAddress;
use Illuminate\Foundation\Http\FormRequest;

class RequestLoginEmailChangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'new_email' => [
                'required',
                'email',
                'max:255',
                new ValidLoginEmailAddress,
                'different:current_email',
            ],
            'new_email_confirmation' => 'required|same:new_email',
            'current_password' => 'required|string',
            'current_email' => 'required|email',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'current_email' => $this->user()?->email,
            'new_email' => strtolower(trim((string) $this->input('new_email', ''))),
            'new_email_confirmation' => strtolower(trim((string) $this->input('new_email_confirmation', ''))),
        ]);
    }

    public function messages(): array
    {
        return [
            'new_email.different' => 'The new email must be different from your current login email.',
            'new_email_confirmation.same' => 'The email confirmation does not match.',
        ];
    }
}
