<?php

namespace App\Services\Ticket;

use App\Models\SupportTicket;
use App\Models\TicketStatusHistory;
use App\Models\User;

class TicketStatusHistoryService
{
    public function recordCreation(SupportTicket $ticket, ?User $user = null, ?string $notes = 'Ticket created'): TicketStatusHistory
    {
        return $this->recordChange(
            $ticket,
            null,
            (string) $ticket->status,
            null,
            (string) $ticket->priority,
            null,
            $ticket->assigned_to_employee_id,
            $user,
            $notes,
        );
    }

    public function recordIfChanged(
        SupportTicket $ticket,
        ?string $fromStatus,
        ?string $fromPriority,
        int|string|null $fromAssignee,
        ?User $user = null,
        ?string $notes = null,
    ): ?TicketStatusHistory {
        $statusChanged = $this->changed($fromStatus, $ticket->status);
        $priorityChanged = $this->changed($fromPriority, $ticket->priority);
        $assigneeChanged = $this->changed($fromAssignee, $ticket->assigned_to_employee_id);

        if (! $statusChanged && ! $priorityChanged && ! $assigneeChanged) {
            return null;
        }

        return $this->recordChange(
            $ticket,
            $fromStatus,
            (string) $ticket->status,
            $fromPriority,
            (string) $ticket->priority,
            $fromAssignee,
            $ticket->assigned_to_employee_id,
            $user,
            $notes,
        );
    }

    public function recordChange(
        SupportTicket $ticket,
        ?string $fromStatus,
        string $toStatus,
        ?string $fromPriority,
        ?string $toPriority,
        int|string|null $fromAssignee,
        int|string|null $toAssignee,
        ?User $user = null,
        ?string $notes = null,
        ?string $changeSource = null,
    ): TicketStatusHistory {
        return TicketStatusHistory::create([
            'support_ticket_id' => $ticket->id,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'from_priority' => $fromPriority,
            'to_priority' => $toPriority,
            'from_assigned_to_employee_id' => $fromAssignee,
            'to_assigned_to_employee_id' => $toAssignee,
            'changed_by_user_id' => $user?->id,
            'change_source' => $changeSource ?? SupportTicket::SOURCE_CRM,
            'notes' => $notes,
            'created_at' => now(),
        ]);
    }

    private function changed(mixed $before, mixed $after): bool
    {
        return (string) ($before ?? '') !== (string) ($after ?? '');
    }
}
