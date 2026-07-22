<?php

namespace App\Events\Ticket;

use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TicketStatusChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly SupportTicket $ticket,
        public readonly string $fromStatus,
        public readonly string $toStatus,
        public readonly ?User $actor = null,
    ) {}
}
