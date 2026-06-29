<?php

namespace App\Http\Requests\FollowUp;

use App\Services\Rbac\RbacService;
use Illuminate\Foundation\Http\FormRequest;

class UpdateFollowUpSequenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = auth()->user();
        if (! $user) {
            return false;
        }

        $role = app(RbacService::class)->userPayload($user)['role'] ?? '';

        return in_array($role, ['super_admin', 'admin'], true);
    }

    public function rules(): array
    {
        return [
            'name' => 'nullable|string|max:255',
            'sequence_days' => 'required|array|min:1',
            'sequence_days.*' => 'integer|min:1|max:365',
            'trigger_outcomes' => 'nullable|array',
            'trigger_outcomes.*' => 'string|max:255',
            'is_active' => 'nullable|boolean',
        ];
    }
}
