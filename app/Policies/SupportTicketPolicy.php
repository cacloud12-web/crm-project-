<?php

namespace App\Policies;

use App\Models\SupportTicket;
use App\Models\User;
use App\Services\Rbac\RbacService;
use App\Services\Ticket\TicketVisibilityService;

class SupportTicketPolicy
{
    public function __construct(
        private readonly RbacService $rbacService,
        private readonly TicketVisibilityService $visibilityService,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->canModule($user, 'view') || $this->canModule($user, 'view_all');
    }

    public function view(User $user, SupportTicket $supportTicket): bool
    {
        return $this->canAccessRecord($user, $supportTicket, 'view');
    }

    public function create(User $user): bool
    {
        return $this->canModule($user, 'create');
    }

    public function update(User $user, SupportTicket $supportTicket): bool
    {
        return $this->canAccessRecord($user, $supportTicket, 'edit');
    }

    public function delete(User $user, SupportTicket $supportTicket): bool
    {
        return $this->canAccessRecord($user, $supportTicket, 'delete');
    }

    public function assign(User $user, SupportTicket $supportTicket): bool
    {
        return $this->canAccessRecord($user, $supportTicket, 'assign')
            || $this->canAccessRecord($user, $supportTicket, 'edit');
    }

    public function downloadAttachment(User $user, SupportTicket $supportTicket): bool
    {
        return $this->canAccessRecord($user, $supportTicket, 'download')
            || $this->canAccessRecord($user, $supportTicket, 'view');
    }

    private function canAccessRecord(User $user, SupportTicket $supportTicket, string $permission): bool
    {
        if (! $this->canModule($user, $permission) && ! ($permission === 'view' && $this->canModule($user, 'view_all'))) {
            return false;
        }

        if ($this->canModule($user, 'view_all') && in_array($permission, ['view', 'download'], true)) {
            return true;
        }

        return $this->visibilityService->canView($supportTicket, $user);
    }

    private function canModule(User $user, string $permission): bool
    {
        return $this->rbacService->can($user, 'tickets', $permission);
    }
}
