<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\Notifications\NotificationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $params = $request->only(['page', 'per_page', 'filter']);

        return ApiResponse::success(
            $this->notificationService->listForUser($request->user(), $params),
            'Notifications loaded',
        );
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return ApiResponse::success([
            'unread_count' => $this->notificationService->unreadCount($request->user()),
        ], 'Unread count loaded');
    }

    public function poll(Request $request): JsonResponse
    {
        $afterId = $request->integer('after_id') ?: null;

        return ApiResponse::success(
            $this->notificationService->poll($request->user(), $afterId),
            'Notifications polled',
        );
    }

    public function markRead(Request $request, int $id): JsonResponse
    {
        $marked = $this->notificationService->markRead($request->user(), $id);

        if (! $marked) {
            return ApiResponse::error('Notification not found.', 404);
        }

        return ApiResponse::success([
            'unread_count' => $this->notificationService->unreadCount($request->user()),
        ], 'Notification marked as read');
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $marked = $this->notificationService->markAllRead($request->user());

        return ApiResponse::success([
            'marked' => $marked,
            'unread_count' => 0,
        ], $marked > 0 ? 'All notifications marked as read' : 'No unread notifications');
    }
}
