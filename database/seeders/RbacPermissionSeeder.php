<?php

namespace Database\Seeders;

use App\Models\CrmPermission;
use App\Models\CrmRole;
use App\Services\Rbac\RbacDatabaseService;
use App\Services\Rbac\RbacMatrixService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RbacPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $modules = config('rbac.modules', []);
        $actions = config('rbac.permissions', []);
        $moduleLabels = config('rbac.module_labels', []);
        $actionLabels = config('rbac.permission_labels', []);
        $matrix = config('rbac.matrix', []);
        $roles = config('rbac.roles', []);

        DB::transaction(function () use ($modules, $actions, $moduleLabels, $actionLabels, $matrix, $roles) {
            $sort = 0;
            foreach ($modules as $module) {
                foreach ($actions as $actionIndex => $action) {
                    CrmPermission::query()->updateOrCreate(
                        ['module' => $module, 'action' => $action],
                        [
                            'label' => $actionLabels[$action] ?? ucwords(str_replace('_', ' ', $action)),
                            'sort_order' => is_int($actionIndex) ? $actionIndex : $sort++,
                        ],
                    );
                }
            }

            foreach ($roles as $roleKey => $roleLabel) {
                CrmRole::query()->updateOrCreate(
                    ['key' => $roleKey],
                    [
                        'label' => $roleLabel,
                        'is_system' => true,
                        'is_editable' => $roleKey !== 'super_admin',
                    ],
                );
            }

            $permissionMap = CrmPermission::query()
                ->get(['id', 'module', 'action'])
                ->keyBy(fn (CrmPermission $row) => $row->module.'.'.$row->action);

            foreach ($matrix as $roleKey => $roleMatrix) {
                if ($roleKey === 'super_admin') {
                    continue;
                }

                $role = CrmRole::query()->where('key', $roleKey)->first();
                if (! $role) {
                    continue;
                }

                DB::table('crm_role_permissions')->where('crm_role_id', $role->id)->delete();

                if (! is_array($roleMatrix)) {
                    continue;
                }

                $rows = [];
                $now = now();
                $grantedPermissionIds = [];

                $grant = function (int $permissionId) use (&$rows, &$grantedPermissionIds, $role, $now): void {
                    if (isset($grantedPermissionIds[$permissionId])) {
                        return;
                    }

                    $grantedPermissionIds[$permissionId] = true;
                    $rows[] = [
                        'crm_role_id' => $role->id,
                        'crm_permission_id' => $permissionId,
                        'granted' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                };

                if (isset($roleMatrix['*']) && in_array('*', $roleMatrix['*'], true)) {
                    foreach ($permissionMap as $permission) {
                        $grant((int) $permission->id);
                    }
                } else {
                    $wildcardActions = (isset($roleMatrix['*']) && is_array($roleMatrix['*']))
                        ? $roleMatrix['*']
                        : [];

                    if ($wildcardActions !== []) {
                        foreach ($modules as $module) {
                            foreach ($wildcardActions as $action) {
                                $permission = $permissionMap->get($module.'.'.$action);
                                if ($permission) {
                                    $grant((int) $permission->id);
                                }
                            }
                        }
                    }

                    foreach ($roleMatrix as $module => $moduleActions) {
                        if ($module === '*' || ! is_array($moduleActions)) {
                            continue;
                        }

                        foreach ($moduleActions as $action) {
                            $permission = $permissionMap->get($module.'.'.$action);
                            if ($permission) {
                                $grant((int) $permission->id);
                            }
                        }
                    }
                }

                if ($rows) {
                    DB::table('crm_role_permissions')->insert($rows);
                }
            }
        });

        app(RbacMatrixService::class)->flushCache();
    }
}
