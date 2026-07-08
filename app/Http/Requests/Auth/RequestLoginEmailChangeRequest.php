<?php

namespace App\Http\Requests\Auth;

use App\Rules\ValidLoginEmailAddress;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
                Rule::unique('users', 'email'),
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
        ]);
    }

    public function messages(): array
    {
        return [
            'new_email.different' => 'The new email must be different from your current login email.',
            'new_email.unique' => 'This email address is already in use.',
            'new_email_confirmation.same' => 'The email confirmation does not match.',
        ];
    }
}
