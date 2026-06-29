<?php

namespace App\Http\Requests\Employee;

use App\Rules\AssignableCrmRole;
use App\Rules\CityBelongsToState;
use Illuminate\Foundation\Http\FormRequest;

class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'email_id' => 'required|email|max:255|unique:employees,email_id|unique:users,email',
            'mobile_no' => 'nullable|string|max:20',
            'state_id' => 'nullable|integer|exists:states,state_id',
            'city_id' => ['nullable', 'integer', 'exists:cities,city_id', new CityBelongsToState],
            'role' => 'nullable|string|max:255',
            'crm_role' => ['required', 'string', 'in:employee,manager,admin', new AssignableCrmRole],
            'password' => 'required|string|min:8|confirmed',
            'date_of_joining' => 'nullable|date',
            'status' => 'nullable|string|max:255',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('crm_role')) {
            $this->merge(['crm_role' => 'employee']);
        }

        if (! $this->filled('role')) {
            $this->merge(['role' => 'Sales Executive']);
        }
    }
}
