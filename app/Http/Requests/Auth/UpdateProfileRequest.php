<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Employee\Concerns\ValidatesEmployeeDemoWorkType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateProfileRequest extends FormRequest
{
    use ValidatesEmployeeDemoWorkType;

    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        $userId = $this->user()?->id;

        return array_merge([
            'name' => 'required|string|max:255',
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($userId),
            ],
            'designation' => 'nullable|string|max:255',
            'mobile_no' => 'nullable|string|max:20',
        ], $this->employeeDemoWorkTypeRules(updating: true));
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('work_type')) {
            $this->prepareEmployeeDemoWorkType();
        }
    }

    public function withValidator(Validator $validator): void
    {
        if ($this->has('work_type')) {
            $this->appendEmployeeDemoWorkTypeValidation($validator);
        }
    }
}
