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
        $role = $this->roleKey($user);
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

    /**
     * @param  list<string>  $modulePermissions
     */
    private function permissionAliasGranted(string $permission, array $modulePermissions): bool
    {
        $aliases = [
            'reports' => ['view_reports', 'reports'],
            'view_reports' => ['reports', 'view_reports'],
            'campaigns' => ['campaigns', 'send_email', 'send_sms'],
            'send_email' => ['campaigns', 'send_email'],
            'send_sms' => ['campaigns', 'send_sms'],
            'assign' => ['assign', 'create'],
            'reassign' => ['reassign', 'edit'],
            'schedule_followup' => ['schedule_followup', 'create'],
            'schedule_demo' => ['schedule_demo', 'create'],
            'mark_completed' => ['mark_completed', 'edit'],
            'manage_settings' => ['manage_settings', 'edit'],
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
        $result = [];

        if ($this->roleHasWildcard($roleMatrix)) {
            foreach ($modules as $module) {
                $result[$module] = config('rbac.permissions', []);
            }

            return $result;
        }

        foreach ($modules as $module) {
            $result[$module] = array_values(array_unique($roleMatrix[$module] ?? $roleMatrix['*'] ?? []));
        }

        return $result;
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

        if (str_starts_with($path, 'admin/db-health') || str_starts_with($path, 'admin/queue-status')) {
            return ['module' => 'admin', 'permission' => 'reports'];
        }

        if (str_starts_with($path, 'admin/security-matrix')) {
            return [
                'module' => 'security',
                'permission' => $method === 'GET' ? 'view' : 'manage_settings',
            ];
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

        if (str_starts_with($path, 'activity-logs')) {
            return ['module' => 'activity', 'permission' => $method === 'GET' ? 'view' : 'reports'];
        }

        if (str_starts_with($path, 'assignment-histories')) {
            return ['module' => 'assignment', 'permission' => 'view'];
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

        if (str_contains($path, 'bulk-import')) {
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
        ) {
            return ['module' => 'campaigns', 'permission' => $method === 'GET' ? 'view' : 'campaigns'];
        }

        if (str_starts_with($path, 'consent-trackings') || str_starts_with($path, 'dnd-management')) {
            return ['module' => 'consent', 'permission' => $this->methodPermission($method)];
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

        $prefixes = [
            'ca-masters' => 'ca_master',
            'employees' => 'employees',
            'follow-ups' => 'followups',
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
