<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupportTicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'serial_number' => $this->serial_number,
            'ticket_number' => $this->ticket_number,
            'customer_name' => $this->customer_name,
            'organization_number' => $this->organization_number,
            'organization_name' => $this->organization_name,
            'raised_by_name' => $this->raised_by_name,
            'raised_by_user_id' => $this->raised_by_user_id,
            'mobile_number' => $this->mobile_number,
            'email' => $this->when(
                $this->email_verification_status === 'verified',
                $this->email,
            ),
            'email_verification_status' => $this->email_verification_status,
            'customer_email_verified_at' => $this->customer_email_verified_at,
            'verification_source' => $this->verification_source,
            'verification_correlation_id' => $this->verification_correlation_id,
            'problem_type' => $this->problem_type,
            'priority' => $this->priority,
            'status' => $this->status,
            'description' => $this->description,
            'admin_remarks' => $this->admin_remarks,
            'assigned_to_employee_id' => $this->assigned_to_employee_id,
            'assigned_to_name' => $this->assignedTo?->name,
            'created_via' => $this->created_via,
            'source_system' => $this->source_system,
            'external_ticket_id' => $this->external_ticket_id,
            'sync_status' => $this->sync_status,
            'synced_at' => $this->synced_at,
            'acknowledged_at' => $this->acknowledged_at,
            'external_updated_at' => $this->external_updated_at,
            'notification_email_status' => $this->notification_email_status,
            'notification_whatsapp_status' => $this->notification_whatsapp_status,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'created_by_name' => $this->createdByUser?->name,
            'updated_by_name' => $this->updatedByUser?->name,
            'raised_by_user' => $this->whenLoaded('raisedByUser', fn () => [
                'id' => $this->raisedByUser?->id,
                'name' => $this->raisedByUser?->name,
                'email' => $this->raisedByUser?->email,
            ]),
            'assigned_to' => $this->whenLoaded('assignedTo', fn () => [
                'employee_id' => $this->assignedTo?->employee_id,
                'name' => $this->assignedTo?->name,
                'role' => $this->assignedTo?->role,
            ]),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'deleted_at' => $this->deleted_at,
        ];
    }
}
