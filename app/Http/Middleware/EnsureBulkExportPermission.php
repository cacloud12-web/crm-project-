<?php

namespace App\Http\Middleware;

use App\Services\Bulk\BulkExportPermissionService;
use Closure;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class EnsureBulkExportPermission
{
    public function __construct(
        private readonly BulkExportPermissionService $permissionService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $this->permissionService->authorize($request);
        } catch (RuntimeException $e) {
            if ($request->expectsJson() || $request->is('ca-masters/bulk-export*')) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 403);
            }

            abort(403, $e->getMessage());
        }

        return $next($request);
    }
}
