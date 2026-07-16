<?php

namespace App\Http\Requests\Attendance;

use App\Models\EmployeeAttendance;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MarkAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'exists:employees,employee_id'],
            'status' => ['required', 'string', Rule::in(EmployeeAttendance::STATUSES)],
            'date' => ['nullable', 'date'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ];
    }
}
