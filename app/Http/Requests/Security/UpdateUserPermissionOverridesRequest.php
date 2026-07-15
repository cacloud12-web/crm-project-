<?php

namespace App\Http\Requests\Security;

use App\Services\Rbac\RbacGrantNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserPermissionOverridesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    protected function prepareForValidation(): void
    {
        $normalizer = app(RbacGrantNormalizer::class);
        $this->merge([
            'allows' => $this->expandOnly($this->input('allows', []), $normalizer),
            'denies' => $this->expandOnly($this->input('denies', []), $normalizer),
        ]);
    }

    /**
     * @param  mixed  $grants
     * @return array<string, list<string>>
     */
    private function expandOnly(mixed $grants, RbacGrantNormalizer $normalizer): array
    {
        if (! is_array($grants)) {
            return [];
        }

        $expanded = [];
        foreach ($grants as $module => $actions) {
            if (! is_string($module) || ! is_array($actions)) {
                continue;
            }
            $clean = [];
            foreach ($actions as $action) {
                if (! is_string($action) || $action === '') {
                    continue;
                }
                foreach ($normalizer->expandLegacyAction($action) as $item) {
                    $clean[] = $item;
                }
            }
            $expanded[$module] = array_values(array_unique($clean));
        }

        return $expanded;
    }

    public function rules(): array
    {
        $actions = config('rbac.matrix_permissions', []);

        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'allows' => ['nullable', 'array'],
            'denies' => ['nullable', 'array'],
            'allows.*' => ['array'],
            'denies.*' => ['array'],
            'allows.*.*' => ['string', Rule::in($actions)],
            'denies.*.*' => ['string', Rule::in($actions)],
        ];
    }
}
