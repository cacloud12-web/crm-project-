<?php

namespace App\Http\Requests\Security;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRolePermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    public function rules(): array
    {
        return [
            'role' => ['required', 'string', Rule::in(['manager', 'employee', 'admin'])],
            'grants' => ['required', 'array'],
            'grants.*' => ['array'],
            'grants.*.*' => ['string', Rule::in(config('rbac.matrix_permissions', []))],
        ];
    }
}
