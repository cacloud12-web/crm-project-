<?php

namespace App\Http\Requests\CaMaster;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCaMasterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $storeRules = (new StoreCaMasterRequest)->rules();
        $rules = [];

        foreach ($storeRules as $field => $rule) {
            if (is_string($rule)) {
                $rules[$field] = 'sometimes|'.$rule;

                continue;
            }

            $rules[$field] = array_merge(['sometimes'], $rule);
        }

        return $rules;
    }
}
