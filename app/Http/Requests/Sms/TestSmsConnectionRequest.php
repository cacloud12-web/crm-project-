<?php

namespace App\Http\Requests\Sms;

use Illuminate\Foundation\Http\FormRequest;

class TestSmsConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'mobileno' => ['required', 'string', 'max:20'],
            'text' => ['required', 'string', 'max:500'],
        ];
    }
}
