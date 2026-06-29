<?php

namespace App\Http\Requests\Security;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSecurityMatrixRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'role' => [
                'required',
                'string',
                Rule::in(array_keys(array_diff_key(config('rbac.roles', []), array_flip(['super_admin'])))),
            ],
            'module' => ['required', 'string', Rule::in(config('rbac.modules', []))],
            'permission' => ['required', 'string', Rule::in(config('rbac.permissions', []))],
            'granted' => 'required|boolean',
        ];
    }
}
