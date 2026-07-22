<?php

namespace App\Events\Ticket;

use App\Models\SupportTicket;
use App\Models\TicketComment;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TicketCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly SupportTicket $ticket,
        public readonly ?User $actor = null,
    ) {}
}
