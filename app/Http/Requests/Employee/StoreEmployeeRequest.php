<?php

namespace App\Http\Requests\Employee;

use App\Http\Requests\Employee\Concerns\ValidatesEmployeeDemoWorkType;
use App\Rules\AssignableCrmRole;
use App\Rules\CityBelongsToState;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreEmployeeRequest extends FormRequest
{
    use ValidatesEmployeeDemoWorkType;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return array_merge([
            'name' => 'required|string|max:255',
            'email_id' => [
                'required', 'email', 'max:255',
                Rule::unique('employees', 'email_id')->whereNull('deleted_at'),
                Rule::unique('users', 'email')->whereNull('deleted_at'),
            ],
            'mobile_no' => 'nullable|string|max:20',
            'state_id' => 'nullable|integer|exists:states,state_id',
            'city_id' => ['nullable', 'integer', 'exists:cities,city_id', new CityBelongsToState],
            'role' => 'nullable|string|max:255',
            'crm_role' => ['required', 'string', 'in:employee,manager,admin', new AssignableCrmRole],
            'password' => 'required|string|min:8|confirmed',
            'date_of_joining' => 'nullable|date',
            'status' => 'nullable|string|max:255',
        ], $this->employeeDemoWorkTypeRules(false));
    }

    public function withValidator(Validator $validator): void
    {
        $this->appendEmployeeDemoWorkTypeValidation($validator);
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('crm_role')) {
            $this->merge(['crm_role' => 'employee']);
        }

        if (! $this->filled('role')) {
            $this->merge(['role' => 'Sales Executive']);
        }

        $this->prepareEmployeeDemoWorkType();
    }
}
