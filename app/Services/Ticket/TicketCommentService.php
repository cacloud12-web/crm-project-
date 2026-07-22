<?php

namespace App\Services\Ticket;

use App\Models\SupportTicket;
use App\Models\TicketComment;
use App\Models\User;
use App\Services\Activity\ActivityLogService;
use App\Services\Rbac\RbacService;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class TicketCommentService
{
    public function __construct(
        private readonly ActivityLogService $activityLogService,
        private readonly TicketVisibilityService $visibilityService,
        private readonly TicketNotificationPreparationService $notificationPreparationService,
        private readonly RbacService $rbacService,
    ) {}

    public function listForTicket(SupportTicket $ticket, ?User $user = null): Collection
    {
        $user ??= auth()->user();
        $this->visibilityService->ensureCanView($ticket, $user);

        return $ticket->comments()
            ->with(['user:id,name,email'])
            ->get()
            ->filter(fn (TicketComment $comment) => $this->canViewComment($comment, $user))
            ->values();
    }

    public function create(SupportTicket $ticket, array $data, ?User $user = null): TicketComment
    {
        $user ??= auth()->user();
        $this->visibilityService->ensureCanView($ticket, $user);

        $isInternal = (bool) ($data['is_internal'] ?? false);
        $visibility = $data['visibility'] ?? ($isInternal ? 'internal' : 'public');

        if ($isInternal || $visibility === 'internal') {
            if (! $this->canPostInternalComment($user)) {
                throw new AccessDeniedHttpException('You cannot post internal comments.');
            }
            $isInternal = true;
            $visibility = 'internal';
        }

        $authorType = $data['author_type'] ?? $this->resolveAuthorType($user);

        $comment = TicketComment::create([
            'support_ticket_id' => $ticket->id,
            'user_id' => $user?->id,
            'author_name' => $data['author_name'] ?? $user?->name,
            'author_type' => $authorType,
            'comment_type' => $data['comment_type'] ?? ($isInternal ? 'internal_note' : 'reply'),
            'body' => $data['body'],
            'visibility' => $visibility,
            'is_internal' => $isInternal,
            'source_system' => $data['source_system'] ?? SupportTicket::SOURCE_CRM,
            'external_comment_id' => $data['external_comment_id'] ?? null,
            'metadata' => $data['metadata'] ?? null,
        ]);

        $this->activityLogService->log(
            'TICKET_MANAGEMENT',
            'Ticket Comment Added',
            (string) $ticket->id,
            $ticket->ticket_number.' · '.mb_substr($comment->body, 0, 120),
        );

        $this->notificationPreparationService->prepareForReplyAdded($ticket, $comment, $user);

        return $comment->load(['user:id,name,email']);
    }

    private function canViewComment(TicketComment $comment, User $user): bool
    {
        if (! $comment->is_internal && $comment->visibility !== 'internal') {
            return true;
        }

        return $this->canPostInternalComment($user);
    }

    private function canPostInternalComment(User $user): bool
    {
        $role = $this->rbacService->roleKey($user);
        if (in_array($role, ['super_admin', 'admin', 'manager'], true)) {
            return true;
        }

        return $this->rbacService->can($user, 'tickets', 'edit');
    }

    private function resolveAuthorType(?User $user): string
    {
        if (! $user) {
            return 'system';
        }

        $role = $this->rbacService->roleKey($user);
        if (in_array($role, ['super_admin', 'admin'], true)) {
            return 'admin';
        }

        return 'employee';
    }
}
