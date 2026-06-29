<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ServeSpaForBrowser
{
    public function handle(Request $request, Closure $next, string $spaPage = 'dashboard'): Response
    {
        if ($request->isMethod('GET') && ! $this->wantsApiResponse($request)) {
            return response()->view('crm.index', ['spaPage' => $spaPage]);
        }

        return $next($request);
    }

    private function wantsApiResponse(Request $request): bool
    {
        if ($request->expectsJson()) {
            return true;
        }

        if ($request->ajax()) {
            return true;
        }

        if ($request->headers->get('X-Requested-With') === 'XMLHttpRequest') {
            return true;
        }

        $accept = (string) $request->headers->get('Accept', '');

        return str_contains($accept, 'application/json');
    }
}
