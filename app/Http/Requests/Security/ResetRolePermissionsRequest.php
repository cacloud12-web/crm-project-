<?php

namespace App\Http\Requests\Security;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResetRolePermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'role' => ['required', 'string', Rule::in(['manager', 'employee', 'admin'])],
        ];
    }
}
