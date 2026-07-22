<?php

namespace App\Services\Rbac;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\Request;

class RbacService
{
    public function roleKey(?User $user): string
    {
        if (! $user) {
            return (string) config('rbac.default_role', 'employee');
        }

        $role = strtolower((string) ($user->crm_role ?? config('rbac.default_role', 'employee')));

        return array_key_exists($role, config('rbac.roles', [])) ? $role : (string) config('rbac.default_role', 'employee');
    }

    public function roleLabel(?User $user): string
    {
        $key = $this->roleKey($user);

        return config("rbac.roles.{$key}", ucfirst(str_replace('_', ' ', $key)));
    }

    public function can(?User $user, string $module, string $permission): bool
    {
        if (! $this->canWithoutParent($user, $module, $permission)) {
            return false;
        }

        // Parent module view required for child actions (deny cannot be bypassed by child allow alone).
        if ($permission !== 'view' && $this->moduleRequiresParentView($module)) {
            return $this->canWithoutParent($user, $module, 'view');
        }

        return true;
    }

    /**
     * Precedence without parent-view dependency:
     * 1. super_admin allow
     * 2. user DENY
     * 3. user ALLOW
     * 4. role matrix
     * 5. default deny
     */
    public function canWithoutParent(?User $user, string $module, string $permission): bool
    {
        if (! $user) {
            return false;
        }

        $role = $this->roleKey($user);
        if ($role === 'super_admin') {
            return true;
        }

        $override = app(RbacUserOverrideService::class)->effectFor($user, $module, $permission);
        if ($override === 'deny') {
            return false;
        }
        if ($override === 'allow') {
            return true;
        }

        $matrix = app(RbacMatrixService::class)->effectiveMatrix();
        $roleMatrix = $matrix[$role] ?? [];

        if ($this->roleHasWildcard($roleMatrix)) {
            return true;
        }

        $modulePermissions = $roleMatrix[$module] ?? $roleMatrix['*'] ?? [];

        if (in_array($permission, $modulePermissions, true)) {
            return true;
        }

        return $this->permissionAliasGranted($permission, $modulePermissions);
    }

    private function moduleRequiresParentView(string $module): bool
    {
        return in_array($module, [
            'campaigns',
            'ca_master',
            'leads',
            'assignment',
            'followups',
            'tickets',
            'sales_list',
            'bulk',
            'reports',
            'consent',
            'email_templates',
            'whatsapp_templates',
            'email_configuration',
            'settings',
            'employees',
            'roles_permissions',
            'admin',
            'activity',
        ], true);
    }

    /**
     * @param  list<string>  $modulePermissions
     */
    private function permissionAliasGranted(string $permission, array $modulePermissions): bool
    {
        $aliases = [
            'reports' => ['view_reports', 'reports'],
            'view_reports' => ['reports', 'view_reports'],
            // Legacy "campaigns" action still counts as any send / view for old DB rows.
            'campaigns' => ['campaigns', 'send_email', 'send_sms', 'view'],
            'send_email' => ['campaigns', 'send_email'],
            'send_sms' => ['campaigns', 'send_sms'],
            'assign' => ['assign', 'create'],
            'reassign' => ['reassign', 'edit'],
            'schedule_followup' => ['schedule_followup', 'create', 'edit'],
            'schedule_demo' => ['schedule_demo', 'create', 'edit'],
            'mark_completed' => ['mark_completed', 'edit'],
            'manage_settings' => ['manage_settings', 'edit'],
            'upload' => ['upload', 'create', 'import'],
            'create' => ['create', 'upload', 'import'],
            'import' => ['import', 'upload', 'create'],
            'process' => ['process', 'upload', 'create', 'import'],
            'retry' => ['retry', 'edit'],
            'download' => ['download', 'view', 'export'],
            'view_all' => ['view_all'],
        ];

        $candidates = $aliases[$permission] ?? [$permission];

        foreach ($candidates as $candidate) {
            if (in_array($candidate, $modulePermissions, true)) {
                return true;
            }
        }

        return false;
    }

    public function permissionsFor(?User $user): array
    {
        $role = $this->roleKey($user);
        $matrix = app(RbacMatrixService::class)->effectiveMatrix();
        $roleMatrix = $matrix[$role] ?? [];
        $modules = config('rbac.modules', []);
        $allActions = config('rbac.permissions', []);
        $result = [];

        if ($this->roleHasWildcard($roleMatrix) || $role === 'super_admin') {
            foreach ($modules as $module) {
                $result[$module] = $allActions;
            }

            return $result;
        }

        foreach ($modules as $module) {
            $result[$module] = array_values(array_unique($roleMatrix[$module] ?? $roleMatrix['*'] ?? []));
        }

        if (! $user) {
            return $result;
        }

        // Apply user overrides for frontend payload (effective permissions).
        $overrides = app(RbacUserOverrideService::class)->overridesForUser($user);
        foreach ($overrides['allows'] as $module => $actions) {
            $result[$module] = array_values(array_unique(array_merge($result[$module] ?? [], $actions)));
        }
        foreach ($overrides['denies'] as $module => $actions) {
            $result[$module] = array_values(array_filter(
                $result[$module] ?? [],
                fn (string $action) => ! in_array($action, $actions, true) && $action !== '*',
            ));
        }

        // Parent view gate: without view, child actions are not effective.
        foreach ($result as $module => $actions) {
            if (! $this->moduleRequiresParentView($module)) {
                continue;
            }
            if (! in_array('view', $actions, true) && ! in_array('*', $actions, true)) {
                $result[$module] = [];
            }
        }

        return $result;
    }

    /**
     * Effective permission check used by controllers/services.
     * Preferred entry point: PermissionService style.
     */
    public function authorize(?User $user, string $module, string $permission): void
    {
        if (! $this->can($user, $module, $permission)) {
            abort(403, 'You do not have permission to access this action.');
        }
    }

    public function userPayload(?User $user): array
    {
        if (! $user) {
            return [
                'authenticated' => false,
                'role' => config('rbac.default_role'),
                'role_label' => 'Guest',
                'permissions' => [],
            ];
        }

        return [
            'authenticated' => true,
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $this->roleKey($user),
            'role_label' => $this->roleLabel($user),
            'permissions' => $this->permissionsFor($user),
            'permission_overrides' => app(RbacUserOverrideService::class)->overridesForUser($user),
            'employee_id' => $this->resolveEmployeeRecordId($user),
            'designation' => $this->resolveEmployeeDesignation($user),
            'mobile' => $this->resolveEmployeeMobile($user),
        ];
    }

    private function resolveEmployeeRecordId(?User $user): ?int
    {
        if (! $user) {
            return null;
        }

        $employeeId = Employee::query()->where('user_id', $user->id)->value('employee_id');

        if ($employeeId) {
            return (int) $employeeId;
        }

        $employeeId = Employee::query()->where('email_id', $user->email)->value('employee_id');

        return $employeeId ? (int) $employeeId : null;
    }

    private function resolveEmployeeDesignation(?User $user): ?string
    {
        if (! $user) {
            return null;
        }

        return Employee::query()
            ->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)->orWhere('email_id', $user->email);
            })
            ->value('role');
    }

    private function resolveEmployeeMobile(?User $user): ?string
    {
        if (! $user) {
            return null;
        }

        return Employee::query()
            ->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)->orWhere('email_id', $user->email);
            })
            ->value('mobile_no');
    }

    public function canAccessSpaPage(?User $user, string $page): bool
    {
        // Employee footer shortcut: Recycle Bin is always available (scoped by existing lead rules).
        if ($page === 'recycle-bin' && $this->roleKey($user) === 'employee') {
            return $this->can($user, 'leads', 'view');
        }

        $rule = config("rbac.spa_pages.{$page}");

        if (! $rule) {
            return $this->can($user, 'dashboard', 'view');
        }

        return $this->can($user, $rule['module'], $rule['permission']);
    }

    public function resolveRequestPermission(Request $request): array
    {
        $method = strtoupper($request->method());
        $path = trim($request->path(), '/');

        if ($path === '' || $path === 'dashboard') {
            return ['module' => 'dashboard', 'permission' => 'view'];
        }

        if ($path === 'dashboard/metrics' || $path === 'dashboard/productivity-employees') {
            return ['module' => 'dashboard', 'permission' => 'view'];
        }

        if ($path === 'dashboard/employee') {
            return ['module' => 'dashboard', 'permission' => 'view'];
        }

        if (str_starts_with($path, 'ca-masters') && $this->roleKey($request->user()) === 'employee') {
            if ($method === 'GET') {
                return ['module' => 'leads', 'permission' => 'view'];
            }

            if ($method === 'DELETE') {
                return ['module' => 'leads', 'permission' => 'delete'];
            }

            if ($method === 'POST' && ! preg_match('#ca-masters/\d+#', $path)) {
                return ['module' => 'leads', 'permission' => 'create'];
            }

            return ['module' => 'leads', 'permission' => 'edit'];
        }

        if (str_starts_with($path, 'lookups/')) {
            return ['module' => 'dashboard', 'permission' => 'view'];
        }

        if (str_starts_with($path, 'reports')) {
            return ['module' => 'reports', 'permission' => $method === 'GET' ? 'reports' : 'export'];
        }

        if (str_starts_with($path, 'search')) {
            return ['module' => 'dashboard', 'permission' => 'view'];
        }

        if ($path === 'auth/profile') {
            return ['module' => 'dashboard', 'permission' => 'view'];
        }

        if ($path === 'auth/change-password') {
            return ['module' => 'settings', 'permission' => 'view'];
        }

        if (str_starts_with($path, 'auth/login-email-change')) {
            return ['module' => 'settings', 'permission' => 'view'];
        }

        if ($path === 'employees/provision-logins' || preg_match('#^employees/\d+/reset-password$#', $path)) {
            return ['module' => 'employees', 'permission' => 'edit'];
        }

        if (str_starts_with($path, 'admin/db-health')) {
            return ['module' => 'admin', 'permission' => 'manage_settings'];
        }

        if (str_starts_with($path, 'admin/queue-status')) {
            return ['module' => 'admin', 'permission' => 'reports'];
        }

        if (str_starts_with($path, 'admin/role-permissions')) {
            return [
                'module' => 'roles_permissions',
                'permission' => $method === 'GET' ? 'view' : 'manage_settings',
            ];
        }

        if (str_starts_with($path, 'settings/data')) {
            return ['module' => 'settings', 'permission' => $method === 'GET' ? 'view' : 'edit'];
        }

        if (str_starts_with($path, 'sms-settings')) {
            return ['module' => 'settings', 'permission' => in_array($method, ['PUT', 'POST'], true) ? 'edit' : 'view'];
        }

        if (str_starts_with($path, 'email-accounts')) {
            return ['module' => 'admin', 'permission' => $method === 'GET' ? 'reports' : 'edit'];
        }

        if (str_starts_with($path, 'email-settings')) {
            return ['module' => 'settings', 'permission' => $method === 'GET' ? 'view' : 'edit'];
        }

        if (str_starts_with($path, 'google-api-settings')) {
            return ['module' => 'settings', 'permission' => in_array($method, ['PUT', 'POST'], true) ? 'edit' : 'view'];
        }

        if (str_starts_with($path, 'reports/exports/')) {
            return ['module' => 'reports', 'permission' => 'export'];
        }

        if (str_starts_with($path, 'ocr-documents')) {
            $permission = match (true) {
                str_contains($path, '/retry') => 'retry',
                str_contains($path, '/preview'),
                str_contains($path, '/download'),
                str_contains($path, '/original') => 'download',
                $method === 'POST' => 'upload',
                $method === 'DELETE' => 'delete',
                in_array($method, ['PUT', 'PATCH'], true) => 'edit',
                default => 'view',
            };

            return ['module' => 'ocr', 'permission' => $permission];
        }

        if (str_starts_with($path, 'master-import-batches')) {
            return [
                'module' => 'ocr',
                'permission' => $method === 'POST' ? 'edit' : 'view',
            ];
        }

        if (str_starts_with($path, 'activity-logs')) {
            return ['module' => 'activity', 'permission' => $method === 'GET' ? 'view' : 'reports'];
        }

        if (str_starts_with($path, 'attendance')) {
            return [
                'module' => 'attendance',
                'permission' => $method === 'GET' ? 'view' : 'edit',
            ];
        }

        if (str_starts_with($path, 'assignment-histories')) {
            return ['module' => 'assignment', 'permission' => 'view'];
        }

        if (str_starts_with($path, 'assignment-dashboard/')) {
            if ($path === 'assignment-dashboard/capacity' && in_array($method, ['PUT', 'PATCH'], true)) {
                return ['module' => 'assignment', 'permission' => 'assign'];
            }

            return ['module' => 'assignment', 'permission' => 'view'];
        }

        if (str_starts_with($path, 'yearly-employee-targets')) {
            if ($path === 'yearly-employee-targets/current-year' && $this->roleKey($request->user()) === 'employee') {
                return ['module' => 'dashboard', 'permission' => 'view'];
            }

            if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                return ['module' => 'assignment', 'permission' => 'assign'];
            }

            return ['module' => 'assignment', 'permission' => 'view'];
        }

        if (str_starts_with($path, 'daily-employee-targets')) {
            if ($method === 'GET' && $this->roleKey($request->user()) === 'employee') {
                return ['module' => 'dashboard', 'permission' => 'view'];
            }

            if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                return ['module' => 'assignment', 'permission' => 'assign'];
            }

            return ['module' => 'assignment', 'permission' => 'view'];
        }

        if (preg_match('#^(states|cities|source-leads|team-sizes|role-masters)/[^/]+/(dependencies|deactivate|reactivate)$#', $path)) {
            return ['module' => 'ca_master', 'permission' => str_ends_with($path, '/dependencies') ? 'view' : 'edit'];
        }

        if (str_starts_with($path, 'lead-actions')) {
            return ['module' => 'leads', 'permission' => 'edit'];
        }

        if (preg_match('#^ca-masters/\d+/status$#', $path) && $method === 'PATCH') {
            return ['module' => 'leads', 'permission' => 'edit'];
        }

        if (preg_match('#^ca-masters/\d+/contact$#', $path) && $method === 'PATCH') {
            return ['module' => 'leads', 'permission' => 'edit'];
        }

        if (preg_match('#^lead-assignments/\d+/status$#', $path) && $method === 'PATCH') {
            return ['module' => 'assignment', 'permission' => 'edit'];
        }

        // Single assign uses POST /lead-assignments — managers have "assign", not "create".
        if ($path === 'lead-assignments' && $method === 'POST') {
            return ['module' => 'assignment', 'permission' => 'assign'];
        }

        if (preg_match('#^lead-assignments/\d+$#', $path) && in_array($method, ['PUT', 'PATCH'], true)) {
            return ['module' => 'assignment', 'permission' => 'reassign'];
        }

        if (preg_match('#^lead-assignments/\d+$#', $path) && $method === 'DELETE') {
            return ['module' => 'assignment', 'permission' => 'reassign'];
        }

        if (str_contains($path, 'bulk-import')) {
            if ($method === 'DELETE') {
                return ['module' => 'bulk', 'permission' => 'delete'];
            }

            return ['module' => 'bulk', 'permission' => $method === 'GET' ? 'view' : 'import'];
        }

        if (str_contains($path, 'bulk-export') || str_starts_with($path, 'listings/')) {
            return ['module' => 'bulk', 'permission' => $method === 'GET' ? 'export' : 'export'];
        }

        if (str_starts_with($path, 'lead-assignments/bulk/leads') || str_starts_with($path, 'lead-assignments/bulk/employees')) {
            return ['module' => 'bulk', 'permission' => 'view'];
        }

        if (str_contains($path, 'bulk-status-update') || str_contains($path, 'lead-assignments/bulk')) {
            return ['module' => 'bulk', 'permission' => 'edit'];
        }

        if (str_contains($path, 'bulk-operations')) {
            return ['module' => 'bulk', 'permission' => 'view'];
        }

        if (str_contains($path, 'listing-filters')) {
            return ['module' => 'ca_master', 'permission' => $method === 'GET' ? 'view' : 'edit'];
        }

        if (
            str_starts_with($path, 'campaigns')
            || str_starts_with($path, 'whatsapp-campaigns')
            || str_starts_with($path, 'email-campaigns')
            || str_starts_with($path, 'sms-campaigns')
            || str_starts_with($path, 'sms-templates')
            || str_starts_with($path, 'wa-message-logs')
            || str_starts_with($path, 'email-logs')
            || str_starts_with($path, 'sms-logs')
            || str_starts_with($path, 'email-inbox')
            || str_starts_with($path, 'email-templates')
            || str_starts_with($path, 'message-templates')
            || str_starts_with($path, 'template-variables')
        ) {
            if ($method === 'GET') {
                return ['module' => 'campaigns', 'permission' => 'view'];
            }

            if (str_starts_with($path, 'sms-campaigns') || str_starts_with($path, 'sms-')) {
                return ['module' => 'campaigns', 'permission' => 'send_sms'];
            }

            if (str_starts_with($path, 'whatsapp-campaigns') || str_starts_with($path, 'wa-')) {
                return ['module' => 'campaigns', 'permission' => 'send_sms'];
            }

            return ['module' => 'campaigns', 'permission' => 'send_email'];
        }

        if (str_starts_with($path, 'consent-trackings') || str_starts_with($path, 'dnd-management')) {
            return ['module' => 'consent', 'permission' => $this->methodPermission($method)];
        }

        if (str_starts_with($path, 'demo-calendar')) {
            if (str_contains($path, '/providers') && in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                return ['module' => 'settings', 'permission' => 'manage_settings'];
            }

            if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                return ['module' => 'followups', 'permission' => 'schedule_demo'];
            }

            return ['module' => 'followups', 'permission' => 'view'];
        }

        if (str_starts_with($path, 'workflow')) {
            return ['module' => 'followups', 'permission' => $this->methodPermission($method)];
        }

        if ($path === 'ca-masters/bulk-delete'
            || $path === 'ca-masters/trashed/restore'
            || $path === 'ca-masters/trashed/force-delete'
            || preg_match('#^ca-masters/\d+/restore$#', $path)
            || preg_match('#^ca-masters/\d+/force$#', $path)) {
            return ['module' => 'ca_master', 'permission' => 'delete'];
        }

        if ($path === 'ca-masters/trashed') {
            return ['module' => 'ca_master', 'permission' => 'delete'];
        }

        if (preg_match('#^ca-masters/\d+/research#', $path)) {
            return ['module' => 'ca_master', 'permission' => 'edit'];
        }

        if (str_starts_with($path, 'ticket-organizations')) {
            return ['module' => 'tickets', 'permission' => 'create'];
        }

        if (str_starts_with($path, 'tickets')) {
            $permission = match (true) {
                str_contains($path, '/attachments/') && str_contains($path, '/download') => 'download',
                str_contains($path, '/attachments') && $method === 'POST' => 'edit',
                str_contains($path, '/assign') && $method === 'POST' => 'edit',
                $path === 'tickets/metadata' => 'view',
                $method === 'POST' && $path === 'tickets' => 'create',
                $method === 'DELETE' => 'delete',
                in_array($method, ['PUT', 'PATCH'], true) => 'edit',
                $method === 'POST' => 'edit',
                default => 'view',
            };

            return ['module' => 'tickets', 'permission' => $permission];
        }

        if (str_starts_with($path, 'follow-ups')) {
            $permission = match ($method) {
                'POST' => 'schedule_followup',
                'PUT', 'PATCH' => 'edit',
                'DELETE' => 'delete',
                default => 'view',
            };

            return ['module' => 'followups', 'permission' => $permission];
        }

        $prefixes = [
            'ca-masters' => 'ca_master',
            'employees' => 'employees',
            'lead-assignments' => 'assignment',
            'states' => 'ca_master',
            'cities' => 'ca_master',
            'source-leads' => 'ca_master',
            'team-sizes' => 'ca_master',
            'role-masters' => 'ca_master',
        ];

        foreach ($prefixes as $prefix => $module) {
            if (str_starts_with($path, $prefix)) {
                return ['module' => $module, 'permission' => $this->methodPermission($method)];
            }
        }

        return ['module' => 'dashboard', 'permission' => 'view'];
    }

    private function methodPermission(string $method): string
    {
        return match ($method) {
            'POST' => 'create',
            'PUT', 'PATCH' => 'edit',
            'DELETE' => 'delete',
            default => 'view',
        };
    }

    private function roleHasWildcard(array $matrix): bool
    {
        return isset($matrix['*']) && in_array('*', $matrix['*'], true);
    }
}
