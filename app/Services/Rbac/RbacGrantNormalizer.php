<?php

namespace App\Services\Rbac;

/**
 * Single source of truth for sanitizing role/user grant payloads
 * so legacy actions never break Saves or silently leave ghost permissions.
 */
class RbacGrantNormalizer
{
    /**
     * Map legacy action tokens to canonical matrix actions.
     *
     * @return list<string>
     */
    public function expandLegacyAction(string $action): array
    {
        return match ($action) {
            'campaigns' => ['view', 'send_email', 'send_sms'],
            'reports' => ['view_reports'],
            default => [$action],
        };
    }

    /**
     * @param  array<string, mixed>  $moduleGrants
     * @return array<string, list<string>>
     */
    public function normalizeModuleGrants(array $moduleGrants, ?array $allowedModules = null, ?array $allowedActions = null): array
    {
        $allowedModules = $allowedModules ?? config('rbac.matrix_modules', config('rbac.modules', []));
        $allowedActions = $allowedActions ?? config('rbac.matrix_permissions', []);
        $normalized = [];

        foreach ($moduleGrants as $module => $actions) {
            if (! is_string($module) || $module === '*' || ! in_array($module, $allowedModules, true)) {
                continue;
            }

            if (! is_array($actions)) {
                continue;
            }

            $clean = [];
            foreach ($actions as $action) {
                if (! is_string($action) || $action === '') {
                    continue;
                }

                foreach ($this->expandLegacyAction($action) as $expanded) {
                    if (in_array($expanded, $allowedActions, true)) {
                        $clean[] = $expanded;
                    }
                }
            }

            $normalized[$module] = array_values(array_unique($clean));
        }

        return $normalized;
    }
}
