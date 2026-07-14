<?php

use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\EnsureBulkExportPermission;
use App\Http\Middleware\EnsureRbacPermission;
use App\Http\Middleware\EnsureSpaPageAccess;
use App\Http\Middleware\RecordEmployeePresence;
use App\Http\Middleware\ServeSpaForBrowser;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'active.user' => EnsureActiveUser::class,
            'spa.browser' => ServeSpaForBrowser::class,
            'bulk.export' => EnsureBulkExportPermission::class,
            'rbac' => EnsureRbacPermission::class,
            'spa.access' => EnsureSpaPageAccess::class,
        ]);

        $middleware->appendToGroup('web', [
            EnsureActiveUser::class,
            RecordEmployeePresence::class,
        ]);

        $middleware->redirectGuestsTo(fn () => route('crm.login'));

        $middleware->validateCsrfTokens(except: [
            'webhooks/whatsapp',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->expectsJson()
                || $request->ajax()
                || $request->wantsJson(),
        );

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->expectsJson() || $request->ajax() || $request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated.',
                ], 401);
            }

            return null;
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) {
            if ($request->expectsJson() || $request->ajax() || $request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage() ?: 'You do not have permission to perform this action.',
                ], 403);
            }

            return null;
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            if ($e instanceof ValidationException) {
                return null;
            }

            if (! $request->expectsJson() && ! $request->ajax() && ! $request->wantsJson()) {
                return null;
            }

            report($e);

            $status = $e instanceof HttpExceptionInterface
                ? $e->getStatusCode()
                : 500;

            if ($status < 400 || $status > 599) {
                $status = 500;
            }

            $message = $status === 403
                ? ($e->getMessage() ?: 'You do not have permission to perform this action.')
                : ($e->getMessage() ?: 'Something went wrong. Please try again.');

            return response()->json([
                'success' => false,
                'message' => $message,
            ], $status);
        });
    })->create();
