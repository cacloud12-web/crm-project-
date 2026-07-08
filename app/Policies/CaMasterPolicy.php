<?php

namespace App\Policies;

use App\Models\CaMaster;
use App\Models\User;
use App\Services\Leads\LeadOwnershipService;

class CaMasterPolicy
{
    public function __construct(
        private readonly LeadOwnershipService $leadOwnership,
    ) {}

    public function view(?User $user, CaMaster $lead): bool
    {
        return $user !== null;
    }

    public function update(?User $user, CaMaster $lead): bool
    {
        return $this->leadOwnership->canEdit($user, $lead);
    }
}
