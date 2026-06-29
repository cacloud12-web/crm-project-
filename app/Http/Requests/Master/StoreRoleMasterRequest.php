<?php

namespace App\Http\Requests\Master;

use Illuminate\Foundation\Http\FormRequest;

class StoreRoleMasterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'role_name' => 'required|string|max:255|unique:role_masters,role_name',
            'description' => 'nullable|string|max:255',
        ];
    }
}
