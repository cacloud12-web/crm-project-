<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyCaCloudDeskIntegrationToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('ca_cloud_desk_integration.inbound_integration_token');
        if ($expected === '') {
            return response()->json([
                'success' => false,
                'message' => 'Inbound ticket integration is not configured.',
            ], 503);
        }

        $provided = (string) (
            $request->header('X-Integration-Token')
            ?: $request->header('X-Api-Key')
            ?: $request->bearerToken()
            ?: ''
        );

        if ($provided === '' || ! hash_equals($expected, $provided)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
                'detail' => 'Unauthorized',
            ], 401);
        }

        return $next($request);
    }
}
