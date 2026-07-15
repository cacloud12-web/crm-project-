<?php

namespace Database\Seeders;

use App\Models\CrmPermission;
use App\Models\CrmRole;
use App\Services\Rbac\RbacGrantNormalizer;
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
        $normalizer = app(RbacGrantNormalizer::class);

        DB::transaction(function () use ($modules, $actions, $actionLabels, $matrix, $roles, $normalizer) {
            foreach ($modules as $module) {
                foreach ($actions as $actionIndex => $action) {
                    CrmPermission::query()->updateOrCreate(
                        ['module' => $module, 'action' => $action],
                        [
                            'label' => $actionLabels[$action] ?? ucwords(str_replace('_', ' ', $action)),
                            'sort_order' => is_int($actionIndex) ? $actionIndex : 0,
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
                if ($roleKey === 'super_admin' || ! is_array($roleMatrix)) {
                    continue;
                }

                $role = CrmRole::query()->where('key', $roleKey)->first();
                if (! $role) {
                    continue;
                }

                // Preserve customized grants — only seed empty roles.
                if (DB::table('crm_role_permissions')->where('crm_role_id', $role->id)->exists()) {
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
                        ? array_values(array_filter($roleMatrix['*'], fn ($a) => $a !== '*'))
                        : [];

                    if ($wildcardActions !== []) {
                        foreach ($modules as $module) {
                            foreach ($wildcardActions as $action) {
                                foreach ($normalizer->expandLegacyAction((string) $action) as $expanded) {
                                    $permission = $permissionMap->get($module.'.'.$expanded);
                                    if ($permission) {
                                        $grant((int) $permission->id);
                                    }
                                }
                            }
                        }
                    }

                    foreach ($roleMatrix as $module => $moduleActions) {
                        if ($module === '*' || ! is_array($moduleActions)) {
                            continue;
                        }
                        foreach ($moduleActions as $action) {
                            foreach ($normalizer->expandLegacyAction((string) $action) as $expanded) {
                                $permission = $permissionMap->get($module.'.'.$expanded);
                                if ($permission) {
                                    $grant((int) $permission->id);
                                }
                            }
                        }
                    }
                }

                if ($rows) {
                    DB::table('crm_role_permissions')->insert($rows);
                }
            }
        });

        // One-time cleanup: strip legacy "campaigns"/"reports" action grants and expand to canonical actions.
        $this->migrateLegacyActionGrants($normalizer);

        app(RbacMatrixService::class)->flushCache();
    }

    private function migrateLegacyActionGrants(RbacGrantNormalizer $normalizer): void
    {
        $legacyActions = ['campaigns', 'reports'];
        $legacyRows = DB::table('crm_role_permissions')
            ->join('crm_permissions', 'crm_permissions.id', '=', 'crm_role_permissions.crm_permission_id')
            ->whereIn('crm_permissions.action', $legacyActions)
            ->get([
                'crm_role_permissions.id as pivot_id',
                'crm_role_permissions.crm_role_id',
                'crm_permissions.module',
                'crm_permissions.action',
            ]);

        if ($legacyRows->isEmpty()) {
            return;
        }

        $permissionMap = CrmPermission::query()
            ->get(['id', 'module', 'action'])
            ->keyBy(fn (CrmPermission $row) => $row->module.'.'.$row->action);

        DB::transaction(function () use ($legacyRows, $permissionMap, $normalizer) {
            $now = now();
            $insert = [];

            foreach ($legacyRows as $row) {
                foreach ($normalizer->expandLegacyAction((string) $row->action) as $expanded) {
                    $permission = $permissionMap->get($row->module.'.'.$expanded);
                    if (! $permission) {
                        continue;
                    }
                    $insert[] = [
                        'crm_role_id' => $row->crm_role_id,
                        'crm_permission_id' => $permission->id,
                        'granted' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            DB::table('crm_role_permissions')
                ->whereIn('id', $legacyRows->pluck('pivot_id')->all())
                ->delete();

            foreach ($insert as $row) {
                $exists = DB::table('crm_role_permissions')
                    ->where('crm_role_id', $row['crm_role_id'])
                    ->where('crm_permission_id', $row['crm_permission_id'])
                    ->exists();
                if (! $exists) {
                    DB::table('crm_role_permissions')->insert($row);
                }
            }
        });
    }
}
