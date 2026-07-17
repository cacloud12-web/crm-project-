<?php

namespace App\Http\Requests\Employee;

use App\Http\Requests\Employee\Concerns\ValidatesEmployeeDemoWorkType;
use App\Models\Employee;
use App\Rules\CityBelongsToState;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateEmployeeRequest extends FormRequest
{
    use ValidatesEmployeeDemoWorkType;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $employeeId = $this->route('employee');

        return array_merge([
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
        ], $this->employeeDemoWorkTypeRules(true));
    }

    public function withValidator(Validator $validator): void
    {
        $this->appendEmployeeDemoWorkTypeValidation($validator);
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('work_type') || $this->has('demo_meeting_link') || $this->has('demo_min_team_size')
            || $this->has('demo_max_team_size') || $this->has('active_for_demo')) {
            if (! $this->filled('work_type')) {
                $employeeId = $this->route('employee');
                $existing = Employee::query()->where('employee_id', $employeeId)->value('work_type');
                $this->merge(['work_type' => $existing ?: 'calling']);
            }
            $this->prepareEmployeeDemoWorkType();
        }
    }
}
