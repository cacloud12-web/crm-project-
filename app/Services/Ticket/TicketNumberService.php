<?php

namespace App\Services\Ticket;

use App\Models\SupportTicket;
use Illuminate\Support\Facades\DB;

class TicketNumberService
{
    /**
     * @return array{serial_number: int, ticket_number: string}
     */
    public function allocate(): array
    {
        return DB::transaction(function () {
            $maxSerial = (int) SupportTicket::withTrashed()->lockForUpdate()->max('serial_number');
            $serial = $maxSerial + 1;

            $prefix = (string) config('crm_tickets.ticket_number_prefix', 'TKT');
            $base = $prefix.'-'.now()->format('Ymd').'-';

            $last = SupportTicket::withTrashed()
                ->where('ticket_number', 'like', $base.'%')
                ->lockForUpdate()
                ->orderByDesc('ticket_number')
                ->value('ticket_number');

            $seq = $last ? ((int) substr((string) $last, -5)) + 1 : 1;

            do {
                $ticketNumber = $base.str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
                $seq++;
            } while (SupportTicket::withTrashed()->where('ticket_number', $ticketNumber)->exists());

            return [
                'serial_number' => $serial,
                'ticket_number' => $ticketNumber,
            ];
        });
    }
}
