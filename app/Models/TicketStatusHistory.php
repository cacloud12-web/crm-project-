<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketStatusHistory extends Model
{
    public $timestamps = false;

    protected $table = 'ticket_status_histories';

    protected $fillable = [
        'support_ticket_id',
        'from_status',
        'to_status',
        'from_priority',
        'to_priority',
        'from_assigned_to_employee_id',
        'to_assigned_to_employee_id',
        'changed_by_user_id',
        'change_source',
        'notes',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    public function changedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }

    public function fromAssignee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'from_assigned_to_employee_id', 'employee_id');
    }

    public function toAssignee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'to_assigned_to_employee_id', 'employee_id');
    }
}
