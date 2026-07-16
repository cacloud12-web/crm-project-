<?php

namespace App\Http\Requests\Attendance;

use App\Models\EmployeeAttendance;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkMarkAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_ids' => ['required', 'array', 'min:1'],
            'employee_ids.*' => ['integer', 'exists:employees,employee_id'],
            'status' => ['required', 'string', Rule::in(EmployeeAttendance::STATUSES)],
            'date' => ['nullable', 'date'],
        ];
    }
}
