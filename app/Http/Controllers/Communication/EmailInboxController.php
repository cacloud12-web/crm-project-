<?php

namespace App\Http\Controllers\Communication;

use App\Http\Controllers\Controller;
use App\Models\EmailAttachment;
use App\Services\Email\EmailInboxService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmailInboxController extends Controller
{
    public function __construct(
        private readonly EmailInboxService $inboxService,
    ) {}

    public function metrics(): JsonResponse
    {
        return ApiResponse::success($this->inboxService->metrics(), 'Email inbox metrics loaded');
    }

    public function index(Request $request): JsonResponse
    {
        $result = $this->inboxService->searchInbox($request->query());
        $items = collect($result['items'] ?? [])->map(fn ($item) => [
            'id' => $item->id,
            'from_email' => $item->from_email,
            'to_email' => $item->to_email,
            'lead_name' => $item->caMaster?->firm_name,
            'ca_id' => $item->ca_id,
            'subject' => $item->subject,
            'body_preview' => \Illuminate\Support\Str::limit(strip_tags((string) ($item->body_text ?: $item->body_html)), 120),
            'received_at' => $item->received_at,
            'is_read' => (bool) $item->is_read,
            'match_status' => $item->match_status,
            'direction' => $item->direction,
            'attachment_count' => $item->attachments?->count() ?? 0,
        ])->values()->all();

        return ApiResponse::success([
            'items' => $items,
            'pagination' => $result['pagination'] ?? null,
            'meta' => $result['meta'] ?? [],
        ], 'Email inbox loaded');
    }

    public function show(int $id): JsonResponse
    {
        return ApiResponse::success($this->inboxService->show($id), 'Email message loaded');
    }

    public function markRead(int $id): JsonResponse
    {
        $this->inboxService->markRead($id, true);

        return ApiResponse::success(null, 'Marked as read');
    }

    public function sync(): JsonResponse
    {
        $result = $this->inboxService->queueSyncLatest();

        return ApiResponse::success($result, (string) ($result['message'] ?? 'Inbox sync started.'));
    }

    public function downloadAttachment(int $id): StreamedResponse|JsonResponse
    {
        $attachment = EmailAttachment::query()->findOrFail($id);
        if (! $attachment->storage_path || ! Storage::disk('local')->exists($attachment->storage_path)) {
            return ApiResponse::error('Attachment file not found.', 404);
        }

        return Storage::disk('local')->download(
            $attachment->storage_path,
            $attachment->filename,
            ['Content-Type' => $attachment->mime_type ?: 'application/octet-stream'],
        );
    }
}
