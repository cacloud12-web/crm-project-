<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Email\StoreEmailAccountRequest;
use App\Http\Requests\Email\TestEmailAccountConnectionRequest;
use App\Http\Requests\Email\UpdateEmailAccountRequest;
use App\Models\EmailSetting;
use App\Services\Email\EmailAccountService;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class EmailAccountController extends Controller
{
    public function __construct(
        private readonly EmailAccountService $emailAccountService,
    ) {}

    public function index(): JsonResponse
    {
        try {
            $this->emailAccountService->ensureSuperAdmin(auth()->user());
        } catch (AuthorizationException $exception) {
            return ApiResponse::error($exception->getMessage(), 403);
        }

        return ApiResponse::success(
            ['items' => $this->emailAccountService->list()],
            'Email accounts loaded',
        );
    }

    public function store(StoreEmailAccountRequest $request): JsonResponse
    {
        try {
            $account = $this->emailAccountService->store($request->validated(), $request->user());
        } catch (AuthorizationException $exception) {
            return ApiResponse::error($exception->getMessage(), 403);
        } catch (ValidationException $exception) {
            return ApiResponse::error($exception->getMessage(), 422, ['errors' => $exception->errors()]);
        }

        return ApiResponse::created($account, 'Email account saved');
    }

    public function update(UpdateEmailAccountRequest $request, int $id): JsonResponse
    {
        try {
            $account = $this->emailAccountService->update(
                $this->emailAccountService->find($id),
                $request->validated(),
                $request->user(),
            );
        } catch (AuthorizationException $exception) {
            return ApiResponse::error($exception->getMessage(), 403);
        } catch (ValidationException $exception) {
            return ApiResponse::error($exception->getMessage(), 422, ['errors' => $exception->errors()]);
        }

        return ApiResponse::success($account, 'Email account updated');
    }

    public function destroy(int $id): JsonResponse
    {
        try {
            $this->emailAccountService->destroy($this->emailAccountService->find($id), auth()->user());
        } catch (AuthorizationException $exception) {
            return ApiResponse::error($exception->getMessage(), 403);
        } catch (InvalidArgumentException $exception) {
            return ApiResponse::error($exception->getMessage(), 422);
        }

        return ApiResponse::success(null, 'Email account deleted');
    }

    public function setDefault(int $id): JsonResponse
    {
        try {
            $account = $this->emailAccountService->setDefault(
                $this->emailAccountService->find($id),
                auth()->user(),
            );
        } catch (AuthorizationException $exception) {
            return ApiResponse::error($exception->getMessage(), 403);
        }

        return ApiResponse::success($account, 'Default email account updated');
    }

    public function testSmtp(TestEmailAccountConnectionRequest $request): JsonResponse
    {
        try {
            $data = $this->mergeExistingCredentials($request->validated());
            $result = $this->emailAccountService->testSmtp($data, $request->user());
        } catch (AuthorizationException $exception) {
            return ApiResponse::error($exception->getMessage(), 403);
        }

        if (! $result['success']) {
            return ApiResponse::error($result['message'], 422, $result);
        }

        return ApiResponse::success($result, $result['message']);
    }

    public function testImap(TestEmailAccountConnectionRequest $request): JsonResponse
    {
        try {
            $data = $this->mergeExistingCredentials($request->validated());
            $result = $this->emailAccountService->testImap($data, $request->user());
        } catch (AuthorizationException $exception) {
            return ApiResponse::error($exception->getMessage(), 403);
        }

        if (! $result['success']) {
            return ApiResponse::error($result['message'], 422, $result);
        }

        return ApiResponse::success($result, $result['message']);
    }

    public function syncImap(int $id): JsonResponse
    {
        try {
            $result = $this->emailAccountService->syncImap(
                $this->emailAccountService->find($id),
                auth()->user(),
            );
        } catch (AuthorizationException $exception) {
            return ApiResponse::error($exception->getMessage(), 403);
        }

        return ApiResponse::success($result, $result['message']);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function mergeExistingCredentials(array $data): array
    {
        if (empty($data['account_id'])) {
            return $data;
        }

        $account = EmailSetting::query()->findOrFail($data['account_id']);
        $merged = array_merge($account->toArray(), $data);

        if (empty($data['smtp_password'])) {
            $merged['smtp_password'] = $account->smtp_password;
        }
        if (empty($data['imap_password'])) {
            $merged['imap_password'] = $account->imap_password;
        }

        return $merged;
    }
}
