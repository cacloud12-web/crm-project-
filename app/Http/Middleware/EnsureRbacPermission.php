<?php

namespace App\Http\Middleware;

use App\Services\Rbac\RbacService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRbacPermission
{
    public function __construct(
        private readonly RbacService $rbacService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $rule = $this->rbacService->resolveRequestPermission($request);

        if (! $this->rbacService->can($user, $rule['module'], $rule['permission'])) {
            if ($this->wantsApiResponse($request)) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to access this action.',
                    'required' => $rule,
                ], 403);
            }

            abort(403, 'You do not have permission to access this action.');
        }

        return $next($request);
    }

    private function wantsApiResponse(Request $request): bool
    {
        return $request->expectsJson()
            || $request->ajax()
            || $request->headers->get('X-Requested-With') === 'XMLHttpRequest'
            || str_contains((string) $request->headers->get('Accept', ''), 'application/json');
    }
}
