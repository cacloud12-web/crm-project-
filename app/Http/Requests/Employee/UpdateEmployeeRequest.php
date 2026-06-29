<?php

namespace App\Http\Requests\Employee;

use App\Models\Employee;
use App\Rules\CityBelongsToState;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $employeeId = $this->route('employee');

        return [
            'name' => 'sometimes|required|string|max:255',
            'email_id' => [
                'sometimes',
                'required',
                'email',
                'max:255',
                Rule::unique('employees', 'email_id')->ignore($employeeId, 'employee_id'),
                Rule::unique('users', 'email')->ignore(
                    optional(Employee::find($employeeId))->user_id,
                    'id',
                ),
            ],
            'mobile_no' => 'nullable|string|max:20',
            'state_id' => 'nullable|integer|exists:states,state_id',
            'city_id' => ['nullable', 'integer', 'exists:cities,city_id', new CityBelongsToState],
            'role' => 'nullable|string|max:255',
            'date_of_joining' => 'nullable|date',
            'status' => 'nullable|string|max:255',
        ];
    }
}
