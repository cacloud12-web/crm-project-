<?php

namespace App\Http\Middleware;

use App\Services\Presence\EmployeePresenceService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RecordEmployeePresence
{
    public function __construct(
        private readonly EmployeePresenceService $presenceService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $user = $request->user();
        if (! $user) {
            return $response;
        }

        // Dedicated heartbeat already updates presence; avoid double-writing.
        if ($request->is('auth/presence/heartbeat')) {
            return $response;
        }

        $throttleSeconds = max(15, (int) config('crm_presence.request_touch_throttle_seconds', 60));
        $sessionKey = 'crm_presence_touched_at';
        $lastTouch = (int) $request->session()->get($sessionKey, 0);
        $now = time();

        if ($lastTouch > 0 && ($now - $lastTouch) < $throttleSeconds) {
            return $response;
        }

        $this->presenceService->touchSafely($user);
        $request->session()->put($sessionKey, $now);

        return $response;
    }
}
