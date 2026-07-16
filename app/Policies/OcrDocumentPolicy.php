<?php

namespace App\Policies;

use App\Models\OcrDocument;
use App\Models\User;
use App\Services\Rbac\EmployeeDataScopeService;
use App\Services\Rbac\RbacService;

class OcrDocumentPolicy
{
    public function __construct(
        private readonly RbacService $rbacService,
        private readonly EmployeeDataScopeService $employeeDataScope,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->canModule($user, 'view') || $this->canModule($user, 'view_all');
    }

    public function view(User $user, OcrDocument $ocrDocument): bool
    {
        return $this->canAccessRecord($user, $ocrDocument, 'view');
    }

    public function create(User $user): bool
    {
        return $this->canModule($user, 'upload')
            || $this->canModule($user, 'create')
            || $this->canModule($user, 'process');
    }

    public function update(User $user, OcrDocument $ocrDocument): bool
    {
        return $this->canAccessRecord($user, $ocrDocument, 'edit');
    }

    public function delete(User $user, OcrDocument $ocrDocument): bool
    {
        return $this->canAccessRecord($user, $ocrDocument, 'delete');
    }

    public function retry(User $user, OcrDocument $ocrDocument): bool
    {
        return $ocrDocument->isFailed()
            && (
                $this->canAccessRecord($user, $ocrDocument, 'retry')
                || $this->canAccessRecord($user, $ocrDocument, 'edit')
            );
    }

    public function download(User $user, OcrDocument $ocrDocument): bool
    {
        return $this->canAccessRecord($user, $ocrDocument, 'download')
            || $this->canAccessRecord($user, $ocrDocument, 'view');
    }

    private function canAccessRecord(User $user, OcrDocument $ocrDocument, string $permission): bool
    {
        if (! $this->canModule($user, $permission) && ! ($permission === 'view' && $this->canModule($user, 'view_all'))) {
            return false;
        }

        if ($this->canModule($user, 'view_all') && in_array($permission, ['view', 'download'], true)) {
            return true;
        }

        if (! $ocrDocument->ca_id) {
            $role = $this->rbacService->roleKey($user);
            if (in_array($role, ['super_admin', 'admin', 'manager'], true)) {
                return true;
            }

            return (int) $ocrDocument->uploaded_by === (int) $user->id;
        }

        try {
            $this->employeeDataScope->ensureCanAccessCaMaster($ocrDocument->ca_id);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException|\Illuminate\Auth\Access\AuthorizationException) {
            return false;
        }

        return true;
    }

    private function canModule(User $user, string $permission): bool
    {
        return $this->rbacService->can($user, 'ocr', $permission);
    }
}
