<?php

namespace App\Services\Ticket;

use App\Exceptions\Ocr\OcrFileException;
use App\Models\SupportTicket;
use App\Models\TicketAttachment;
use App\Models\User;
use App\Services\Activity\ActivityLogService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TicketAttachmentService
{
    public function __construct(
        private readonly ActivityLogService $activityLogService,
        private readonly TicketVisibilityService $visibilityService,
    ) {}

    public function listForTicket(SupportTicket $ticket, ?User $user = null): Collection
    {
        $user ??= auth()->user();
        $this->visibilityService->ensureCanView($ticket, $user);

        return $ticket->attachments()
            ->with(['uploader:id,name,email'])
            ->get();
    }

    public function store(
        SupportTicket $ticket,
        UploadedFile $file,
        ?User $user = null,
        ?int $commentId = null,
    ): TicketAttachment {
        $user ??= auth()->user();
        $this->visibilityService->ensureCanView($ticket, $user);
        $this->assertValidUpload($file);

        $disk = (string) config('crm_tickets.storage_disk', 'local');
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
        $storedFilename = (string) Str::uuid().'.'.$extension;
        $storagePath = sprintf(
            'ticket-attachments/%s/%s/%s',
            now()->format('Y'),
            now()->format('m'),
            $storedFilename,
        );

        $storedPath = $file->storeAs(
            dirname($storagePath),
            basename($storagePath),
            ['disk' => $disk, 'visibility' => 'private'],
        );

        if (! $storedPath) {
            throw new OcrFileException('Unable to store the attachment.', 'storage_failed');
        }

        $checksum = hash_file('sha256', $file->getRealPath() ?: '');

        $attachment = TicketAttachment::create([
            'support_ticket_id' => $ticket->id,
            'ticket_comment_id' => $commentId,
            'uploaded_by' => $user?->id,
            'original_filename' => $file->getClientOriginalName(),
            'stored_filename' => $storedFilename,
            'storage_disk' => $disk,
            'storage_path' => $storedPath,
            'mime_type' => (string) ($file->getMimeType() ?: 'application/octet-stream'),
            'file_size' => (int) $file->getSize(),
            'checksum' => $checksum ?: null,
        ]);

        $this->activityLogService->log(
            'TICKET_MANAGEMENT',
            'Ticket Attachment Uploaded',
            (string) $ticket->id,
            $ticket->ticket_number.' · '.$attachment->original_filename,
        );

        return $attachment->load(['uploader:id,name,email']);
    }

    public function download(TicketAttachment $attachment, ?User $user = null): StreamedResponse
    {
        $user ??= auth()->user();
        $ticket = $attachment->ticket()->firstOrFail();
        $this->visibilityService->ensureCanView($ticket, $user);

        if (! Storage::disk($attachment->storage_disk)->exists($attachment->storage_path)) {
            abort(404, 'Attachment file not found.');
        }

        $this->activityLogService->log(
            'TICKET_MANAGEMENT',
            'Ticket Attachment Downloaded',
            (string) $ticket->id,
            $ticket->ticket_number.' · '.$attachment->original_filename,
        );

        return Storage::disk($attachment->storage_disk)->response(
            $attachment->storage_path,
            $attachment->original_filename,
            [
                'Content-Type' => $attachment->mime_type,
                'Content-Disposition' => 'attachment; filename="'.addslashes($attachment->original_filename).'"',
            ],
        );
    }

    private function assertValidUpload(UploadedFile $file): void
    {
        $maxBytes = (int) config('crm_tickets.max_attachment_mb', 20) * 1024 * 1024;
        if ($file->getSize() > $maxBytes) {
            throw new OcrFileException(
                'Attachment exceeds the maximum allowed size.',
                'file_too_large',
            );
        }

        $mime = strtolower((string) $file->getMimeType());
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: '');
        $allowedMimes = array_map('strtolower', config('crm_tickets.allowed_mime_types', []));
        $allowedExtensions = array_map('strtolower', config('crm_tickets.allowed_extensions', []));

        if ($allowedMimes !== [] && ! in_array($mime, $allowedMimes, true)) {
            throw new OcrFileException('This file type is not allowed.', 'unsupported_mime');
        }

        if ($allowedExtensions !== [] && $extension !== '' && ! in_array($extension, $allowedExtensions, true)) {
            throw new OcrFileException('This file extension is not allowed.', 'unsupported_extension');
        }
    }
}
