<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RequestLoginEmailChangeRequest;
use App\Services\Auth\LoginEmailChangeService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoginEmailChangeController extends Controller
{
    public function __construct(
        private readonly LoginEmailChangeService $loginEmailChangeService,
    ) {}

    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success(
            $this->loginEmailChangeService->status($request->user()),
            'Login email change status loaded.',
        );
    }

    public function history(Request $request): JsonResponse
    {
        return ApiResponse::success(
            $this->loginEmailChangeService->history($request->user()),
            'Login email change history loaded.',
        );
    }

    public function store(RequestLoginEmailChangeRequest $request): JsonResponse
    {
        $this->loginEmailChangeService->requestChange(
            $request->user(),
            $request->validated('new_email'),
            $request->validated('current_password'),
            $request->ip(),
            $request->userAgent(),
        );

        return ApiResponse::success(
            $this->loginEmailChangeService->status($request->user()->fresh()),
            'Verification email sent to your new address. Your current login email remains active until verification is complete.',
        );
    }

    public function resend(Request $request): JsonResponse
    {
        $this->loginEmailChangeService->resendVerification(
            $request->user(),
            $request->ip(),
            $request->userAgent(),
        );

        return ApiResponse::success(
            $this->loginEmailChangeService->status($request->user()->fresh()),
            'Verification email resent successfully.',
        );
    }

    public function cancel(Request $request): JsonResponse
    {
        $this->loginEmailChangeService->cancelPending(
            $request->user(),
            $request->ip(),
            $request->userAgent(),
        );

        return ApiResponse::success(
            $this->loginEmailChangeService->status($request->user()->fresh()),
            'Pending login email change cancelled.',
        );
    }

    public function verify(Request $request, string $token): View
    {
        try {
            $this->loginEmailChangeService->verify(
                $token,
                $request->ip(),
                $request->userAgent(),
            );

            return view('crm.auth.verify-login-email', [
                'success' => true,
                'title' => 'Login email verified',
                'message' => 'Your login email has been updated. You can now sign in with your new email address.',
            ]);
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first()
                ?: 'This verification link is invalid or has expired.';

            return view('crm.auth.verify-login-email', [
                'success' => false,
                'title' => 'Verification failed',
                'message' => $message,
            ]);
        }
    }
}
