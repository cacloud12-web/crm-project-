<?php

namespace App\Http\Middleware;

use App\Services\Rbac\RbacService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSpaPageAccess
{
    public function __construct(
        private readonly RbacService $rbacService,
    ) {}

    public function handle(Request $request, Closure $next, string $spaPage = 'dashboard'): Response
    {
        if (! $this->rbacService->canAccessSpaPage($request->user(), $spaPage)) {
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to access this action.',
                ], 403);
            }

            abort(403, 'You do not have permission to access this action.');
        }

        return $next($request);
    }
}
