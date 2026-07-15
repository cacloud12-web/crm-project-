<?php

namespace App\Services\Rbac;

use App\Models\CrmPermission;
use App\Models\CrmUserPermissionOverride;
use App\Models\User;
use App\Services\Activity\ActivityLogService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * User-level allow/deny overlays on top of role matrix.
 *
 * Precedence (RbacService::can):
 * 1. super_admin → allow
 * 2. explicit user DENY → deny
 * 3. explicit user ALLOW → allow
 * 4. role matrix → allow/deny
 * 5. default deny
 */
class RbacUserOverrideService
{
    private const CACHE_PREFIX = 'crm:rbac:user_overrides:';

    public function __construct(
        private readonly ActivityLogService $activityLogService,
        private readonly RbacGrantNormalizer $normalizer,
    ) {}

    /**
     * @return array{allows: array<string, list<string>>, denies: array<string, list<string>>}
     */
    public function overridesForUser(User $user): array
    {
        if (! Schema::hasTable('crm_user_permission_overrides')) {
            return ['allows' => [], 'denies' => []];
        }

        return Cache::remember($this->cacheKey($user->id), 300, function () use ($user) {
            $rows = CrmUserPermissionOverride::query()
                ->where('user_id', $user->id)
                ->with('permission:id,module,action')
                ->get();

            $allows = [];
            $denies = [];

            foreach ($rows as $row) {
                $permission = $row->permission;
                if (! $permission) {
                    continue;
                }
                if ($row->effect === 'deny') {
                    $denies[$permission->module][] = $permission->action;
                } else {
                    $allows[$permission->module][] = $permission->action;
                }
            }

            foreach (array_keys($allows) as $module) {
                $allows[$module] = array_values(array_unique($allows[$module]));
            }
            foreach (array_keys($denies) as $module) {
                $denies[$module] = array_values(array_unique($denies[$module]));
            }

            return ['allows' => $allows, 'denies' => $denies];
        });
    }

    /**
     * @return 'allow'|'deny'|null
     */
    public function effectFor(User $user, string $module, string $permission): ?string
    {
        $overrides = $this->overridesForUser($user);
        $denies = $overrides['denies'][$module] ?? [];
        if (in_array($permission, $denies, true) || in_array('*', $denies, true)) {
            return 'deny';
        }

        $allows = $overrides['allows'][$module] ?? [];
        if (in_array($permission, $allows, true) || in_array('*', $allows, true)) {
            return 'allow';
        }

        // Legacy aliases on overrides (send_email granted via campaigns token)
        foreach ($this->normalizer->expandLegacyAction($permission) as $candidate) {
            if ($candidate === $permission) {
                continue;
            }
            if (in_array($candidate, $denies, true)) {
                return 'deny';
            }
            if (in_array($candidate, $allows, true)) {
                return 'allow';
            }
        }

        // If permission is send_email/send_sms, also check deny/allow on legacy "campaigns" action key
        if (in_array('campaigns', $denies, true) && in_array($permission, ['send_email', 'send_sms', 'campaigns'], true)) {
            return 'deny';
        }
        if (in_array('campaigns', $allows, true) && in_array($permission, ['send_email', 'send_sms', 'campaigns'], true)) {
            return 'allow';
        }

        return null;
    }

    /**
     * Replace all overrides for a user with the provided allow/deny maps.
     *
     * @param  array<string, list<string>>  $allows
     * @param  array<string, list<string>>  $denies
     */
    public function saveOverrides(User $actor, User $target, array $allows, array $denies): array
    {
        if (! Schema::hasTable('crm_user_permission_overrides')) {
            throw new InvalidArgumentException('User permission overrides are not available. Run migrations first.');
        }

        if (app(RbacService::class)->roleKey($target) === 'super_admin') {
            throw new InvalidArgumentException('Super Admin permissions cannot receive user overrides.');
        }

        $allows = $this->normalizer->normalizeModuleGrants($allows);
        $denies = $this->normalizer->normalizeModuleGrants($denies);

        // Child actions imply parent view allow when not explicitly denied.
        foreach ($allows as $module => $actions) {
            if ($actions === []) {
                continue;
            }
            $nonView = array_values(array_filter($actions, fn (string $a) => $a !== 'view'));
            if ($nonView !== [] && ! in_array('view', $actions, true)) {
                $deniedView = in_array('view', $denies[$module] ?? [], true);
                if (! $deniedView) {
                    $allows[$module][] = 'view';
                    $allows[$module] = array_values(array_unique($allows[$module]));
                }
            }
        }

        $permissionMap = CrmPermission::query()
            ->get(['id', 'module', 'action'])
            ->keyBy(fn (CrmPermission $row) => $row->module.'.'.$row->action);

        DB::transaction(function () use ($actor, $target, $allows, $denies, $permissionMap) {
            CrmUserPermissionOverride::query()->where('user_id', $target->id)->delete();

            $now = now();
            $rows = [];

            $push = function (string $effect, array $map) use (&$rows, $target, $actor, $permissionMap, $now): void {
                foreach ($map as $module => $actions) {
                    foreach ($actions as $action) {
                        $permission = $permissionMap->get($module.'.'.$action);
                        if (! $permission) {
                            continue;
                        }
                        $rows[] = [
                            'user_id' => $target->id,
                            'crm_permission_id' => $permission->id,
                            'effect' => $effect,
                            'created_by' => $actor->id,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }
            };

            $push('allow', $allows);
            $push('deny', $denies);

            if ($rows) {
                // Avoid unique conflicts if same permission in both maps — deny wins.
                $keyed = [];
                foreach ($rows as $row) {
                    $key = $row['user_id'].':'.$row['crm_permission_id'];
                    if (isset($keyed[$key]) && $keyed[$key]['effect'] === 'deny') {
                        continue;
                    }
                    if (($row['effect'] === 'deny') || ! isset($keyed[$key])) {
                        $keyed[$key] = $row;
                    }
                }
                DB::table('crm_user_permission_overrides')->insert(array_values($keyed));
            }
        });

        $this->forgetUserCache($target->id);
        app(RbacMatrixService::class)->flushCache();

        $this->activityLogService->log(
            'SECURITY',
            'User Permission Overrides Saved',
            (string) $target->id,
            'Updated user permission overrides for '.$target->email,
            $actor->name,
        );

        return $this->overridesForUser($target->fresh() ?? $target);
    }

    public function clearOverrides(User $actor, User $target): array
    {
        if (! Schema::hasTable('crm_user_permission_overrides')) {
            return ['allows' => [], 'denies' => []];
        }

        CrmUserPermissionOverride::query()->where('user_id', $target->id)->delete();
        $this->forgetUserCache($target->id);
        app(RbacMatrixService::class)->flushCache();

        $this->activityLogService->log(
            'SECURITY',
            'User Permission Overrides Cleared',
            (string) $target->id,
            'Cleared user permission overrides for '.$target->email,
            $actor->name,
        );

        return $this->overridesForUser($target);
    }

    public function forgetUserCache(int $userId): void
    {
        Cache::forget($this->cacheKey($userId));
    }

    private function cacheKey(int $userId): string
    {
        return self::CACHE_PREFIX.$userId;
    }
}
