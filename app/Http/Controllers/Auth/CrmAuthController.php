<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\LoginRateLimiterService;
use App\Services\Presence\EmployeePresenceService;
use App\Services\Rbac\RbacService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CrmAuthController extends Controller
{
    public function __construct(
        private readonly RbacService $rbacService,
        private readonly LoginRateLimiterService $loginRateLimiter,
        private readonly EmployeePresenceService $presenceService,
    ) {}

    public function showLogin(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->intended(config('rbac.login_redirect', '/dashboard'));
        }

        return view('crm.login');
    }

    public function login(Request $request): JsonResponse|RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if ($this->loginRateLimiter->isLockedOut($request)) {
            throw ValidationException::withMessages([
                'email' => [$this->loginRateLimiter->lockoutMessage()],
            ]);
        }

        if (! Auth::attempt($credentials, (bool) $request->boolean('remember'))) {
            $this->loginRateLimiter->recordFailedAttempt($request);

            throw ValidationException::withMessages([
                'email' => ['Invalid email or password.'],
            ]);
        }

        $user = Auth::user();
        if (! $user->is_active) {
            Auth::logout();
            $this->loginRateLimiter->recordFailedAttempt($request);

            throw ValidationException::withMessages([
                'email' => ['Invalid email or password.'],
            ]);
        }

        $this->loginRateLimiter->clear($request);

        $request->session()->regenerate();

        $this->presenceService->touchSafely($user);

        if ($this->wantsApiResponse($request)) {
            return ApiResponse::success(
                $this->rbacService->userPayload($user),
                'Signed in successfully',
            );
        }

        return redirect()->intended(config('rbac.login_redirect', '/dashboard'));
    }

    public function logout(Request $request): JsonResponse|RedirectResponse
    {
        $user = Auth::user();
        $this->presenceService->markOfflineSafely($user);

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($this->wantsApiResponse($request)) {
            return ApiResponse::success(null, 'Signed out successfully');
        }

        return redirect()->route('crm.login');
    }

    public function me(Request $request): JsonResponse
    {
        return ApiResponse::success(
            $this->rbacService->userPayload($request->user()),
            'Current user loaded',
        );
    }

    private function wantsApiResponse(Request $request): bool
    {
        return $request->expectsJson()
            || $request->ajax()
            || $request->headers->get('X-Requested-With') === 'XMLHttpRequest'
            || str_contains((string) $request->headers->get('Accept', ''), 'application/json');
    }
}
