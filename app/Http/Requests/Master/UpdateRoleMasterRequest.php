<?php

namespace App\Http\Requests\Master;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRoleMasterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $roleId = $this->route('role_master');

        return [
            'role_name' => [
                'required',
                'string',
                'max:120',
                Rule::unique('role_masters', 'role_name')->ignore($roleId, 'id'),
            ],
            'description' => 'nullable|string|max:255',
        ];
    }
}
