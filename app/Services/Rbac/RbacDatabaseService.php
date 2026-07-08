<?php

namespace App\Services\Rbac;

use App\Models\CrmPermission;
use App\Models\CrmRole;
use Illuminate\Support\Facades\DB;

class RbacDatabaseService
{
    public function isSeeded(): bool
    {
        return CrmRole::query()->exists() && CrmPermission::query()->exists();
    }

    /**
     * @return array<string, array<string, list<string>>>
     */
    public function buildMatrix(): array
    {
        if (! $this->isSeeded()) {
            return config('rbac.matrix', []);
        }

        $matrix = [];
        $roles = CrmRole::query()->orderBy('id')->get();

        foreach ($roles as $role) {
            if ($role->key === 'super_admin') {
                $matrix[$role->key] = ['*' => ['*']];

                continue;
            }

            $grants = DB::table('crm_role_permissions')
                ->join('crm_permissions', 'crm_permissions.id', '=', 'crm_role_permissions.crm_permission_id')
                ->where('crm_role_permissions.crm_role_id', $role->id)
                ->where('crm_role_permissions.granted', true)
                ->get(['crm_permissions.module', 'crm_permissions.action']);

            $roleMatrix = [];
            foreach ($grants as $grant) {
                $roleMatrix[$grant->module][] = $grant->action;
            }

            foreach ($roleMatrix as $module => $actions) {
                $roleMatrix[$module] = array_values(array_unique($actions));
            }

            $matrix[$role->key] = $roleMatrix;
        }

        return $matrix;
    }

    /**
     * @param  array<string, list<string>>  $moduleGrants
     */
    public function saveRolePermissions(string $roleKey, array $moduleGrants): void
    {
        $role = CrmRole::query()->where('key', $roleKey)->firstOrFail();

        if (! $role->is_editable || $role->key === 'super_admin') {
            throw new \InvalidArgumentException('This role cannot be modified.');
        }

        $permissionMap = CrmPermission::query()
            ->get(['id', 'module', 'action'])
            ->keyBy(fn (CrmPermission $row) => $row->module.'.'.$row->action);

        DB::transaction(function () use ($role, $moduleGrants, $permissionMap) {
            DB::table('crm_role_permissions')->where('crm_role_id', $role->id)->delete();

            $rows = [];
            $now = now();

            foreach ($moduleGrants as $module => $actions) {
                if (! is_array($actions)) {
                    continue;
                }

                foreach (array_unique($actions) as $action) {
                    $permission = $permissionMap->get($module.'.'.$action);
                    if (! $permission) {
                        continue;
                    }

                    $rows[] = [
                        'crm_role_id' => $role->id,
                        'crm_permission_id' => $permission->id,
                        'granted' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            if ($rows) {
                DB::table('crm_role_permissions')->insert($rows);
            }
        });
    }

    public function resetRoleToDefault(string $roleKey): void
    {
        $defaults = config('rbac.matrix.'.$roleKey);

        if (! is_array($defaults)) {
            throw new \InvalidArgumentException('No default permissions configured for this role.');
        }

        if (isset($defaults['*']) && in_array('*', $defaults['*'], true)) {
            throw new \InvalidArgumentException('This role cannot be reset.');
        }

        $this->saveRolePermissions($roleKey, $defaults);
    }

    /**
     * @return list<array{module: string, action: string, label: string|null}>
     */
    public function permissionCatalog(): array
    {
        return CrmPermission::query()
            ->orderBy('module')
            ->orderBy('sort_order')
            ->orderBy('action')
            ->get(['module', 'action', 'label'])
            ->map(fn (CrmPermission $row) => [
                'module' => $row->module,
                'action' => $row->action,
                'label' => $row->label,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{key: string, label: string, is_editable: bool}>
     */
    public function roleCatalog(): array
    {
        return CrmRole::query()
            ->orderBy('id')
            ->get(['key', 'label', 'is_editable'])
            ->map(fn (CrmRole $row) => [
                'key' => $row->key,
                'label' => $row->label,
                'is_editable' => (bool) $row->is_editable,
            ])
            ->values()
            ->all();
    }
}
