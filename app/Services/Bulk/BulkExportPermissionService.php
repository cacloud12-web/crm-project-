<?php

namespace App\Services\Bulk;

use App\Services\Rbac\RbacService;
use Illuminate\Http\Request;
use RuntimeException;

class BulkExportPermissionService
{
    public function __construct(
        private readonly RbacService $rbacService,
    ) {}

    public function authorize(?Request $request = null): void
    {
        if (! config('bulk.export_enabled', true)) {
            throw new RuntimeException('Bulk export is disabled.');
        }

        $request ??= request();
        $user = $request->user();

        if (! $this->rbacService->can($user, 'bulk', 'export')) {
            throw new RuntimeException('You do not have permission to export records.');
        }
    }
}
