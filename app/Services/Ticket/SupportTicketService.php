<?php

namespace App\Services\Ticket;

use App\Models\SupportTicket;
use App\Models\TicketOrganizationLookup;
use App\Models\User;
use App\Services\Activity\ActivityLogService;
use App\Services\Concerns\SearchesListings;
use App\Services\Rbac\EmployeeDataScopeService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SupportTicketService
{
    use SearchesListings;

    public function __construct(
        private readonly ActivityLogService $activityLogService,
        private readonly TicketVisibilityService $visibilityService,
        private readonly EmployeeDataScopeService $employeeDataScope,
        private readonly TicketNumberService $ticketNumberService,
        private readonly TicketStatusHistoryService $statusHistoryService,
        private readonly TicketNotificationPreparationService $notificationPreparationService,
    ) {}

    public function search(array $params = [], ?User $user = null): array
    {
        $user ??= auth()->user();
        $query = SupportTicket::query()->with($this->detailRelations());
        $this->visibilityService->applyVisibilityScope($query, $user);

        return $this->searchListing($query, $params, 'support_tickets');
    }

    public function list(?User $user = null): Collection
    {
        $user ??= auth()->user();
        $query = SupportTicket::query()->with($this->detailRelations());
        $this->visibilityService->applyVisibilityScope($query, $user);

        return $this->listAllFromSearch($query, [], 'support_tickets');
    }

    public function find(int|string $id, ?User $user = null): SupportTicket
    {
        $ticket = SupportTicket::query()
            ->with($this->detailRelations())
            ->findOrFail($id);

        $this->visibilityService->ensureCanView($ticket, $user);

        return $ticket;
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return [
            'problem_types' => config('crm_tickets.problem_types', []),
            'statuses' => config('crm_tickets.statuses', []),
            'priorities' => config('crm_tickets.priorities', []),
            'created_via_values' => config('crm_tickets.created_via_values', []),
            'email_verification_statuses' => config('crm_tickets.email_verification_statuses', []),
            'sync_statuses' => config('crm_tickets.sync_statuses', []),
            'source_systems' => config('crm_tickets.source_systems', []),
            'comment_types' => config('crm_tickets.comment_types', []),
            'author_types' => config('crm_tickets.author_types', []),
            'comment_visibilities' => config('crm_tickets.comment_visibilities', []),
            'max_attachment_mb' => (int) config('crm_tickets.max_attachment_mb', 20),
            'allowed_mime_types' => config('crm_tickets.allowed_mime_types', []),
            'allowed_extensions' => config('crm_tickets.allowed_extensions', []),
        ];
    }

    public function create(array $data, ?User $user = null): SupportTicket
    {
        $user ??= auth()->user();
        $assignedTo = $this->resolveAssignedEmployeeId($data, $user, true);
        $verification = $this->resolveVerifiedOrganization($data, $user);

        return DB::transaction(function () use ($data, $user, $assignedTo, $verification) {
            $identifiers = $this->ticketNumberService->allocate();

            $ticket = SupportTicket::create([
                'serial_number' => $identifiers['serial_number'],
                'ticket_number' => $identifiers['ticket_number'],
                'customer_name' => $data['customer_name'],
                // Organization + email always come from verified lookup — never from browser.
                'organization_number' => $verification['organization_number'],
                'organization_name' => $verification['organization_name'],
                'raised_by_name' => $data['raised_by_name'] ?? $user?->name,
                'raised_by_user_id' => $data['raised_by_user_id'] ?? $user?->id,
                'mobile_number' => $verification['mobile_number'],
                'email' => $verification['email'],
                'customer_email_verified_at' => $verification['verified_at'],
                'verification_source' => $verification['verification_source'],
                'email_verification_status' => $verification['email_verification_status'],
                'verification_correlation_id' => $verification['verification_correlation_id'],
                'problem_type' => $data['problem_type'],
                'priority' => $data['priority'] ?? 'normal',
                'status' => $data['status'] ?? 'open',
                'description' => $data['description'],
                'admin_remarks' => $data['admin_remarks'] ?? null,
                'assigned_to_employee_id' => $assignedTo,
                'created_via' => $data['created_via'] ?? SupportTicket::CREATED_VIA_CRM_EMPLOYEE,
                'source_system' => $data['source_system'] ?? SupportTicket::SOURCE_CRM,
                'external_ticket_id' => $data['external_ticket_id'] ?? null,
                'external_updated_at' => $data['external_updated_at'] ?? null,
                'sync_status' => $data['sync_status'] ?? 'pending',
                'created_by' => $user?->id,
                'updated_by' => $user?->id,
            ]);

            $this->statusHistoryService->recordCreation($ticket, $user);
            $this->logActivity('Ticket Created', $ticket, null, $this->summarySnapshot($ticket));
            $this->notificationPreparationService->prepareForTicketCreated($ticket, $user);

            return $ticket->load($this->detailRelations());
        });
    }

    public function update(SupportTicket $ticket, array $data, ?User $user = null): SupportTicket
    {
        $user ??= auth()->user();
        $this->visibilityService->ensureCanView($ticket, $user);

        $before = $this->summarySnapshot($ticket);
        $previousStatus = $ticket->status;
        $previousPriority = $ticket->priority;
        $previousAssignee = $ticket->assigned_to_employee_id;

        if (array_key_exists('assigned_to_employee_id', $data)) {
            $data['assigned_to_employee_id'] = $this->resolveAssignedEmployeeId(
                $data,
                $user,
                false,
                $ticket,
            );
        }

        // Organization + email are immutable from CRM update — only verified lookup may set them.
        unset(
            $data['organization_number'],
            $data['organization_name'],
            $data['email'],
            $data['verification_correlation_id'],
            $data['customer_email_verified_at'],
            $data['verification_source'],
            $data['email_verification_status'],
        );

        $ticket->fill([
            'customer_name' => $data['customer_name'] ?? $ticket->customer_name,
            'raised_by_name' => $data['raised_by_name'] ?? $ticket->raised_by_name,
            'mobile_number' => $data['mobile_number'] ?? $ticket->mobile_number,
            'problem_type' => $data['problem_type'] ?? $ticket->problem_type,
            'priority' => $data['priority'] ?? $ticket->priority,
            'status' => $data['status'] ?? $ticket->status,
            'description' => $data['description'] ?? $ticket->description,
            'admin_remarks' => array_key_exists('admin_remarks', $data) ? $data['admin_remarks'] : $ticket->admin_remarks,
            'assigned_to_employee_id' => array_key_exists('assigned_to_employee_id', $data)
                ? $data['assigned_to_employee_id']
                : $ticket->assigned_to_employee_id,
            'external_updated_at' => $data['external_updated_at'] ?? $ticket->external_updated_at,
            'updated_by' => $user?->id,
        ]);

        $ticket->save();

        $history = $this->statusHistoryService->recordIfChanged(
            $ticket,
            $previousStatus,
            $previousPriority,
            $previousAssignee,
            $user,
        );

        if ($history && $previousStatus !== $ticket->status) {
            $this->notificationPreparationService->prepareForStatusChanged(
                $ticket->fresh(),
                (string) $previousStatus,
                (string) $ticket->status,
                $user,
            );
        }

        $after = $this->summarySnapshot($ticket->fresh());
        $this->logActivity('Ticket Updated', $ticket, $before, $after);

        return $ticket->fresh()->load($this->detailRelations());
    }

    public function changeStatus(SupportTicket $ticket, string $status, ?User $user = null, ?string $notes = null): SupportTicket
    {
        $user ??= auth()->user();
        $this->visibilityService->ensureCanView($ticket, $user);

        if (! in_array($status, config('crm_tickets.statuses', []), true)) {
            throw new InvalidArgumentException('Invalid ticket status.');
        }

        $before = $this->summarySnapshot($ticket);
        $previousStatus = $ticket->status;

        if ($previousStatus === $status) {
            return $ticket->load($this->detailRelations());
        }

        $ticket->update([
            'status' => $status,
            'updated_by' => $user?->id,
        ]);

        $this->statusHistoryService->recordChange(
            $ticket,
            $previousStatus,
            $status,
            $ticket->priority,
            $ticket->priority,
            $ticket->assigned_to_employee_id,
            $ticket->assigned_to_employee_id,
            $user,
            $notes,
        );

        $this->notificationPreparationService->prepareForStatusChanged($ticket->fresh(), $previousStatus, $status, $user);
        $this->logActivity('Ticket Updated', $ticket, $before, $this->summarySnapshot($ticket->fresh()));

        return $ticket->fresh()->load($this->detailRelations());
    }

    public function assign(SupportTicket $ticket, int $employeeId, ?User $user = null): SupportTicket
    {
        return $this->update($ticket, [
            'assigned_to_employee_id' => $employeeId,
        ], $user);
    }

    public function delete(SupportTicket $ticket, ?User $user = null): void
    {
        $user ??= auth()->user();
        $this->visibilityService->ensureCanView($ticket, $user);

        $before = $this->summarySnapshot($ticket);
        $ticket->delete();

        $this->logActivity('Ticket Deleted', $ticket, $before, null);
    }

    /**
     * @return list<int|string, mixed>
     */
    private function detailRelations(): array
    {
        return [
            'assignedTo:employee_id,name,role',
            'raisedByUser:id,name,email',
            'createdByUser:id,name',
            'updatedByUser:id,name',
        ];
    }

    /**
     * Resolve organization + email exclusively from a verified lookup record.
     *
     * @return array{
     *     mobile_number: string,
     *     organization_number: string,
     *     organization_name: string,
     *     email: string|null,
     *     verified_at: \Illuminate\Support\Carbon|null,
     *     verification_source: string|null,
     *     email_verification_status: string,
     *     verification_correlation_id: string|null
     * }
     */
    private function resolveVerifiedOrganization(array $data, ?User $user = null): array
    {
        $sourceSystem = $data['source_system'] ?? SupportTicket::SOURCE_CRM;
        $createdVia = $data['created_via'] ?? SupportTicket::CREATED_VIA_CRM_EMPLOYEE;

        // Integration / system creates may supply verified fields server-side (Phase 7).
        if ($sourceSystem !== SupportTicket::SOURCE_CRM || in_array($createdVia, [
            SupportTicket::CREATED_VIA_CA_CLOUD_DESK,
            SupportTicket::CREATED_VIA_API,
            SupportTicket::CREATED_VIA_SYSTEM,
        ], true)) {
            if (empty($data['organization_number']) || empty($data['organization_name'])) {
                throw new InvalidArgumentException('Organization details are required for integration tickets.');
            }

            return [
                'mobile_number' => (string) ($data['mobile_number'] ?? ''),
                'organization_number' => (string) $data['organization_number'],
                'organization_name' => (string) $data['organization_name'],
                'email' => $data['email'] ?? null,
                'verified_at' => $data['customer_email_verified_at'] ?? null,
                'verification_source' => $data['verification_source'] ?? $sourceSystem,
                'email_verification_status' => $data['email_verification_status'] ?? 'skipped',
                'verification_correlation_id' => $data['verification_correlation_id'] ?? null,
            ];
        }

        $correlationId = $data['verification_correlation_id'] ?? null;
        if (! $correlationId) {
            throw new InvalidArgumentException('Organization verification is required before creating this ticket.');
        }

        $lookup = TicketOrganizationLookup::query()
            ->where('correlation_id', $correlationId)
            ->first();

        if (! $lookup || $lookup->isExpired()) {
            throw new InvalidArgumentException('Organization verification has expired. Please verify again.');
        }

        if (! $lookup->isVerified()) {
            throw new InvalidArgumentException('Organization verification must succeed before ticket creation.');
        }

        if (! filled($lookup->organization_number) || ! filled($lookup->organization_name)) {
            throw new InvalidArgumentException('Verified organization details are incomplete.');
        }

        if ($lookup->mobile_number !== (string) ($data['mobile_number'] ?? '')) {
            throw new InvalidArgumentException('Mobile number does not match the verified organization lookup.');
        }

        return [
            'mobile_number' => (string) $lookup->mobile_number,
            'organization_number' => (string) $lookup->organization_number,
            'organization_name' => (string) $lookup->organization_name,
            'email' => $lookup->verified_email,
            'verified_at' => $lookup->verified_at ?? now(),
            'verification_source' => 'ca_cloud_desk',
            'email_verification_status' => 'verified',
            'verification_correlation_id' => $correlationId,
        ];
    }

    private function resolveAssignedEmployeeId(
        array $data,
        User $user,
        bool $isCreate,
        ?SupportTicket $existing = null,
    ): ?int {
        $requested = array_key_exists('assigned_to_employee_id', $data)
            ? ($data['assigned_to_employee_id'] !== null ? (int) $data['assigned_to_employee_id'] : null)
            : null;

        if ($requested === null) {
            if ($isCreate) {
                return $this->employeeDataScope->scopedEmployeeId($user)
                    ?: $this->employeeDataScope->resolveEmployeeId($user);
            }

            return $existing?->assigned_to_employee_id;
        }

        if (! $this->visibilityService->canAssignToEmployee($requested, $user)) {
            throw new InvalidArgumentException('You cannot assign tickets to this employee.');
        }

        return $requested;
    }

    /**
     * @return array<string, mixed>
     */
    private function summarySnapshot(SupportTicket $ticket): array
    {
        return [
            'id' => $ticket->id,
            'ticket_number' => $ticket->ticket_number,
            'status' => $ticket->status,
            'priority' => $ticket->priority,
            'problem_type' => $ticket->problem_type,
            'assigned_to_employee_id' => $ticket->assigned_to_employee_id,
            'customer_name' => $ticket->customer_name,
            'organization_number' => $ticket->organization_number,
        ];
    }

    private function logActivity(string $action, SupportTicket $ticket, mixed $before, mixed $after): void
    {
        $this->activityLogService->log(
            'TICKET_MANAGEMENT',
            $action,
            (string) $ticket->id,
            $ticket->ticket_number.' · '.$ticket->customer_name,
            null,
            null,
            $before,
            $after,
        );
    }
}
