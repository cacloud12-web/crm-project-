<?php

namespace App\Http\Requests\Master;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTeamSizeMasterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'team_size_min' => 'required|integer|min:0',
            'team_size_max' => 'required|integer|gte:team_size_min',
            'team_size_label' => 'required|string|max:120',
        ];
    }
}
