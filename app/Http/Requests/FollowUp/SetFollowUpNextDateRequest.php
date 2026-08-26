<?php

namespace App\Http\Requests\FollowUp;

use Illuminate\Foundation\Http\FormRequest;

class SetFollowUpNextDateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'next_followup_date' => 'nullable|date',
            'next_followup_time' => 'nullable|date_format:H:i',
            'create_reminder' => 'nullable|boolean',
            'clear' => 'nullable|boolean',
        ];
    }
}
