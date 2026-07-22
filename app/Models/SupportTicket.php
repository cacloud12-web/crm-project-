<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SupportTicket extends Model
{
    use SoftDeletes;

    public const SOURCE_CRM = 'crm';

    public const SOURCE_CA_CLOUD_DESK = 'ca_cloud_desk';

    public const CREATED_VIA_CRM_EMPLOYEE = 'crm_employee';

    public const CREATED_VIA_CRM_CLIENT = 'crm_client';

    public const CREATED_VIA_CA_CLOUD_DESK = 'ca_cloud_desk';

    public const CREATED_VIA_API = 'api';

    public const CREATED_VIA_SYSTEM = 'system';

    protected $fillable = [
        'serial_number',
        'ticket_number',
        'customer_name',
        'organization_number',
        'organization_name',
        'raised_by_name',
        'raised_by_user_id',
        'mobile_number',
        'email',
        'customer_email_verified_at',
        'verification_source',
        'email_verification_status',
        'verification_correlation_id',
        'problem_type',
        'priority',
        'status',
        'description',
        'admin_remarks',
        'assigned_to_employee_id',
        'created_via',
        'source_system',
        'external_ticket_id',
        'sync_status',
        'synced_at',
        'acknowledged_at',
        'external_updated_at',
        'external_payload',
        'notification_email_status',
        'notification_whatsapp_status',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'serial_number' => 'integer',
            'external_payload' => 'array',
            'customer_email_verified_at' => 'datetime',
            'synced_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'external_updated_at' => 'datetime',
            'verification_correlation_id' => 'string',
        ];
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assigned_to_employee_id', 'employee_id');
    }

    public function raisedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'raised_by_user_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function organizationLookup(): BelongsTo
    {
        return $this->belongsTo(TicketOrganizationLookup::class, 'verification_correlation_id', 'correlation_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TicketComment::class, 'support_ticket_id')->latest('created_at');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TicketAttachment::class, 'support_ticket_id')->latest('created_at');
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(TicketStatusHistory::class, 'support_ticket_id')->latest('created_at');
    }

    public function syncLogs(): HasMany
    {
        return $this->hasMany(TicketSyncLog::class, 'support_ticket_id')->latest('created_at');
    }

    public function notificationLogs(): HasMany
    {
        return $this->hasMany(TicketNotificationLog::class, 'support_ticket_id')->latest('created_at');
    }
}
