<?php

namespace App\Http\Requests\CaMaster;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCaMasterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return (new StoreCaMasterRequest)->rules();
    }
}
